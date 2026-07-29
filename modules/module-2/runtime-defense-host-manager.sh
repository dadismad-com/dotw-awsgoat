#!/usr/bin/env bash
set -euo pipefail

# Runtime defense host lifecycle helper.
# - build: provisions/reuses a blue-team EC2 host, deploys awsgoat-app + tetragon,
#          and applies enforcing TracingPolicies.
# - status: shows host, endpoint, container, and policy status.
# - destroy: terminates the host and optionally removes associated EIP.
#
# Examples:
#   ./runtime-defense-host-manager.sh build
#   ./runtime-defense-host-manager.sh build --no-eip
#   ./runtime-defense-host-manager.sh status
#   ./runtime-defense-host-manager.sh destroy
#
# Optional env overrides:
#   REGION=us-east-1
#   HOST_NAME=tetragon-runtime-defense-demo
#   INSTANCE_TYPE=t3.medium
#   IMAGE_URI=408704473483.dkr.ecr.us-east-1.amazonaws.com/aws-goat-m2:latest

REGION="${REGION:-us-east-1}"
HOST_NAME="${HOST_NAME:-tetragon-runtime-defense-demo}"
INSTANCE_TYPE="${INSTANCE_TYPE:-t3.medium}"
IMAGE_URI="${IMAGE_URI:-408704473483.dkr.ecr.us-east-1.amazonaws.com/aws-goat-m2:latest}"
TETRAGON_IMAGE="${TETRAGON_IMAGE:-quay.io/cilium/tetragon:v1.4.0}"
ROLE_NAME="${ROLE_NAME:-tetragon-demo-role}"
PROFILE_NAME="${PROFILE_NAME:-tetragon-demo-profile}"
VPC_NAME_TAG="${VPC_NAME_TAG:-AWS_GOAT_VPC}"
SG_NAME="${SG_NAME:-tetragon-demo-sg}"
EIP_NAME_TAG="${EIP_NAME_TAG:-tetragon-demo-eip}"
DB_IDENTIFIER="${DB_IDENTIFIER:-aws-goat-db}"
ALLOCATE_EIP=true

ACTION="${1:-}"
if [[ -z "${ACTION}" ]]; then
  echo "Usage: $0 <build|status|destroy> [--no-eip]"
  exit 1
fi
shift || true

for arg in "$@"; do
  case "$arg" in
    --no-eip) ALLOCATE_EIP=false ;;
    *)
      echo "Unknown option: $arg"
      echo "Usage: $0 <build|status|destroy> [--no-eip]"
      exit 1
      ;;
  esac
done

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Missing required command: $1"
    exit 1
  }
}

require_cmd aws

get_running_instance_id() {
  aws ec2 describe-instances \
    --region "${REGION}" \
    --filters "Name=tag:Name,Values=${HOST_NAME}" "Name=instance-state-name,Values=running,pending,stopped,stopping" \
    --query 'Reservations[].Instances[0].InstanceId' \
    --output text
}

wait_for_ssm_online() {
  local instance_id="$1"
  local max_attempts=60
  local attempt=1
  while (( attempt <= max_attempts )); do
    local status
    status="$(aws ssm describe-instance-information \
      --region "${REGION}" \
      --query "InstanceInformationList[?InstanceId=='${instance_id}'].PingStatus | [0]" \
      --output text)"
    if [[ "${status}" == "Online" ]]; then
      return 0
    fi
    sleep 5
    attempt=$((attempt + 1))
  done
  echo "FAIL: SSM agent for ${instance_id} did not become Online in time."
  exit 1
}

ensure_iam_role_profile() {
  if ! aws iam get-role --role-name "${ROLE_NAME}" >/dev/null 2>&1; then
    aws iam create-role \
      --role-name "${ROLE_NAME}" \
      --description "Minimal role for Tetragon runtime-defense demo host" \
      --assume-role-policy-document '{
        "Version":"2012-10-17",
        "Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]
      }' >/dev/null
  fi

  aws iam attach-role-policy \
    --role-name "${ROLE_NAME}" \
    --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore >/dev/null

  aws iam attach-role-policy \
    --role-name "${ROLE_NAME}" \
    --policy-arn arn:aws:iam::aws:policy/AmazonEC2ContainerRegistryReadOnly >/dev/null

  if ! aws iam get-instance-profile --instance-profile-name "${PROFILE_NAME}" >/dev/null 2>&1; then
    aws iam create-instance-profile --instance-profile-name "${PROFILE_NAME}" >/dev/null
  fi

  if ! aws iam get-instance-profile --instance-profile-name "${PROFILE_NAME}" \
    --query "InstanceProfile.Roles[?RoleName=='${ROLE_NAME}'] | length(@)" --output text | grep -q '^1$'; then
    aws iam add-role-to-instance-profile \
      --instance-profile-name "${PROFILE_NAME}" \
      --role-name "${ROLE_NAME}" >/dev/null || true
  fi
}

lookup_network() {
  VPC_ID="$(aws ec2 describe-vpcs \
    --region "${REGION}" \
    --filters "Name=tag:Name,Values=${VPC_NAME_TAG}" \
    --query 'Vpcs[0].VpcId' \
    --output text)"

  if [[ -z "${VPC_ID}" || "${VPC_ID}" == "None" ]]; then
    echo "FAIL: could not find VPC tagged Name=${VPC_NAME_TAG}."
    echo "Run module-2 Terraform Apply first."
    exit 1
  fi

  SUBNET_ID="$(aws ec2 describe-subnets \
    --region "${REGION}" \
    --filters "Name=vpc-id,Values=${VPC_ID}" "Name=map-public-ip-on-launch,Values=true" \
    --query 'Subnets[0].SubnetId' \
    --output text)"
  if [[ -z "${SUBNET_ID}" || "${SUBNET_ID}" == "None" ]]; then
    SUBNET_ID="$(aws ec2 describe-subnets \
      --region "${REGION}" \
      --filters "Name=vpc-id,Values=${VPC_ID}" \
      --query 'Subnets[0].SubnetId' \
      --output text)"
  fi
  if [[ -z "${SUBNET_ID}" || "${SUBNET_ID}" == "None" ]]; then
    echo "FAIL: no subnet found in VPC ${VPC_ID}."
    exit 1
  fi
}

ensure_security_group() {
  SG_ID="$(aws ec2 describe-security-groups \
    --region "${REGION}" \
    --filters "Name=vpc-id,Values=${VPC_ID}" "Name=group-name,Values=${SG_NAME}" \
    --query 'SecurityGroups[0].GroupId' \
    --output text)"

  if [[ -z "${SG_ID}" || "${SG_ID}" == "None" ]]; then
    SG_ID="$(aws ec2 create-security-group \
      --region "${REGION}" \
      --group-name "${SG_NAME}" \
      --description "Runtime defense demo host security group" \
      --vpc-id "${VPC_ID}" \
      --query 'GroupId' \
      --output text)"
  fi

  aws ec2 authorize-security-group-ingress \
    --region "${REGION}" \
    --group-id "${SG_ID}" \
    --ip-permissions '[{"IpProtocol":"tcp","FromPort":80,"ToPort":80,"IpRanges":[{"CidrIp":"0.0.0.0/0"}]}]' >/dev/null 2>&1 || true

  DB_SG_ID="$(aws ec2 describe-security-groups \
    --region "${REGION}" \
    --filters "Name=vpc-id,Values=${VPC_ID}" "Name=group-name,Values=Database Security Group" \
    --query 'SecurityGroups[0].GroupId' \
    --output text)"
  if [[ -n "${DB_SG_ID}" && "${DB_SG_ID}" != "None" ]]; then
    aws ec2 authorize-security-group-ingress \
      --region "${REGION}" \
      --group-id "${DB_SG_ID}" \
      --ip-permissions "[{\"IpProtocol\":\"tcp\",\"FromPort\":3306,\"ToPort\":3306,\"UserIdGroupPairs\":[{\"GroupId\":\"${SG_ID}\"}]}]" >/dev/null 2>&1 || true
  fi
}

lookup_ami_and_db() {
  AMI_ID="$(aws ec2 describe-images \
    --region "${REGION}" \
    --owners amazon \
    --filters "Name=name,Values=al2023-ami-2023.*-x86_64" "Name=state,Values=available" \
    --query 'sort_by(Images,&CreationDate)[-1].ImageId' \
    --output text)"
  if [[ -z "${AMI_ID}" || "${AMI_ID}" == "None" ]]; then
    echo "FAIL: could not resolve Amazon Linux 2023 AMI."
    exit 1
  fi

  RDS_ENDPOINT="$(aws rds describe-db-instances \
    --region "${REGION}" \
    --db-instance-identifier "${DB_IDENTIFIER}" \
    --query 'DBInstances[0].Endpoint.Address' \
    --output text)"
  if [[ -z "${RDS_ENDPOINT}" || "${RDS_ENDPOINT}" == "None" ]]; then
    echo "FAIL: RDS endpoint not found for ${DB_IDENTIFIER}."
    exit 1
  fi
}

launch_or_start_instance() {
  INSTANCE_ID="$(get_running_instance_id)"
  if [[ -n "${INSTANCE_ID}" && "${INSTANCE_ID}" != "None" ]]; then
    STATE="$(aws ec2 describe-instances \
      --region "${REGION}" \
      --instance-ids "${INSTANCE_ID}" \
      --query 'Reservations[0].Instances[0].State.Name' \
      --output text)"
    if [[ "${STATE}" == "stopped" ]]; then
      aws ec2 start-instances --region "${REGION}" --instance-ids "${INSTANCE_ID}" >/dev/null
    fi
  else
    USER_DATA=$'#cloud-config\npackage_update: true\npackages:\n  - docker\nruncmd:\n  - systemctl enable docker\n  - systemctl start docker\n'
    INSTANCE_ID="$(aws ec2 run-instances \
      --region "${REGION}" \
      --image-id "${AMI_ID}" \
      --instance-type "${INSTANCE_TYPE}" \
      --subnet-id "${SUBNET_ID}" \
      --security-group-ids "${SG_ID}" \
      --iam-instance-profile Name="${PROFILE_NAME}" \
      --associate-public-ip-address \
      --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=${HOST_NAME}}]" \
      --user-data "${USER_DATA}" \
      --query 'Instances[0].InstanceId' \
      --output text)"
  fi

  aws ec2 wait instance-running --region "${REGION}" --instance-ids "${INSTANCE_ID}"
  wait_for_ssm_online "${INSTANCE_ID}"
}

ensure_eip() {
  PUBLIC_IP=""
  if [[ "${ALLOCATE_EIP}" != "true" ]]; then
    PUBLIC_IP="$(aws ec2 describe-instances \
      --region "${REGION}" \
      --instance-ids "${INSTANCE_ID}" \
      --query 'Reservations[0].Instances[0].PublicIpAddress' \
      --output text)"
    return
  fi

  EIP_ALLOC_ID="$(aws ec2 describe-addresses \
    --region "${REGION}" \
    --filters "Name=tag:Name,Values=${EIP_NAME_TAG}" \
    --query 'Addresses[0].AllocationId' \
    --output text)"

  if [[ -z "${EIP_ALLOC_ID}" || "${EIP_ALLOC_ID}" == "None" ]]; then
    EIP_ALLOC_ID="$(aws ec2 allocate-address --region "${REGION}" --domain vpc --query 'AllocationId' --output text)"
    aws ec2 create-tags --region "${REGION}" --resources "${EIP_ALLOC_ID}" --tags "Key=Name,Value=${EIP_NAME_TAG}" >/dev/null
  fi

  aws ec2 associate-address \
    --region "${REGION}" \
    --instance-id "${INSTANCE_ID}" \
    --allocation-id "${EIP_ALLOC_ID}" \
    --allow-reassociation >/dev/null

  PUBLIC_IP="$(aws ec2 describe-addresses \
    --region "${REGION}" \
    --allocation-ids "${EIP_ALLOC_ID}" \
    --query 'Addresses[0].PublicIp' \
    --output text)"
}

configure_host() {
  REMOTE=$(cat <<EOF
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
  sudo dnf install -y docker || sudo yum install -y docker
fi
if ! command -v aws >/dev/null 2>&1; then
  sudo dnf install -y awscli || sudo yum install -y awscli
fi

sudo systemctl enable docker
sudo systemctl restart docker

aws ecr get-login-password --region ${REGION} | sudo docker login --username AWS --password-stdin 408704473483.dkr.ecr.us-east-1.amazonaws.com

sudo docker rm -f awsgoat-app tetragon >/dev/null 2>&1 || true
sudo docker pull ${IMAGE_URI}

sudo docker run -d --name awsgoat-app --restart unless-stopped -p 80:80 \\
  -e RDS_ENDPOINT=${RDS_ENDPOINT} \\
  ${IMAGE_URI}

sudo docker run -d --name tetragon --restart unless-stopped \\
  --privileged --pid=host --cgroupns=host \\
  -v /sys/kernel/btf/vmlinux:/var/lib/tetragon/btf \\
  -v /lib/modules:/lib/modules \\
  -v /usr/src:/usr/src \\
  -v /var/run/docker.sock:/var/run/docker.sock \\
  ${TETRAGON_IMAGE}

cat > /tmp/block-reverse-shell.yaml <<'POLICY1'
apiVersion: cilium.io/v1alpha1
kind: TracingPolicy
metadata:
  name: block-reverse-shell
spec:
  kprobes:
  - call: "sys_execve"
    syscall: true
    args:
    - index: 0
      type: "char_buf"
    selectors:
    - matchArgs:
      - index: 0
        operator: "Equal"
        values:
        - "/bin/sh"
      matchActions:
      - action: Sigkill
    - matchArgs:
      - index: 0
        operator: "Equal"
        values:
        - "/bin/bash"
      matchActions:
      - action: Sigkill
    - matchArgs:
      - index: 0
        operator: "Equal"
        values:
        - "/bin/dash"
      matchActions:
      - action: Sigkill
    - matchArgs:
      - index: 0
        operator: "Equal"
        values:
        - "sh"
      matchActions:
      - action: Sigkill
    - matchArgs:
      - index: 0
        operator: "Equal"
        values:
        - "bash"
      matchActions:
      - action: Sigkill
    - matchArgs:
      - index: 0
        operator: "Equal"
        values:
        - "dash"
      matchActions:
      - action: Sigkill
POLICY1

cat > /tmp/block-ptrace-breakout.yaml <<'POLICY2'
apiVersion: cilium.io/v1alpha1
kind: TracingPolicy
metadata:
  name: block-ptrace-breakout
spec:
  kprobes:
  - call: "sys_ptrace"
    syscall: true
    selectors:
    - matchActions:
      - action: Sigkill
POLICY2

sudo docker cp /tmp/block-reverse-shell.yaml tetragon:/tmp/block-reverse-shell.yaml
sudo docker cp /tmp/block-ptrace-breakout.yaml tetragon:/tmp/block-ptrace-breakout.yaml
sudo docker exec tetragon tetra tracingpolicy delete block-reverse-shell >/dev/null 2>&1 || true
sudo docker exec tetragon tetra tracingpolicy delete block-ptrace-breakout >/dev/null 2>&1 || true
sudo docker exec tetragon tetra tracingpolicy add /tmp/block-reverse-shell.yaml
sudo docker exec tetragon tetra tracingpolicy add /tmp/block-ptrace-breakout.yaml
sudo docker exec tetragon tetra tracingpolicy list
EOF
)

  REMOTE_B64="$(printf '%s' "${REMOTE}" | base64)"
  CMD_ID="$(aws ssm send-command \
    --region "${REGION}" \
    --instance-ids "${INSTANCE_ID}" \
    --document-name "AWS-RunShellScript" \
    --parameters "commands=[\"echo ${REMOTE_B64} | base64 -d > /tmp/runtime-defense-build.sh\",\"bash /tmp/runtime-defense-build.sh\"]" \
    --query 'Command.CommandId' \
    --output text)"

  set +e
  aws ssm wait command-executed --region "${REGION}" --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}"
  WAIT_RC=$?
  set -e
  STATUS="$(aws ssm get-command-invocation --region "${REGION}" --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --query 'Status' --output text)"
  OUTPUT="$(aws ssm get-command-invocation --region "${REGION}" --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --query 'StandardOutputContent' --output text)"
  ERRORS="$(aws ssm get-command-invocation --region "${REGION}" --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --query 'StandardErrorContent' --output text)"
  echo "${OUTPUT}"
  if [[ -n "${ERRORS}" && "${ERRORS}" != "None" ]]; then
    echo "== remote stderr =="
    echo "${ERRORS}"
  fi
  if [[ ${WAIT_RC} -ne 0 || "${STATUS}" != "Success" ]]; then
    echo "FAIL: remote configuration failed with status ${STATUS}"
    exit 1
  fi
}

show_status() {
  INSTANCE_ID="$(get_running_instance_id)"
  if [[ -z "${INSTANCE_ID}" || "${INSTANCE_ID}" == "None" ]]; then
    echo "No ${HOST_NAME} host found in ${REGION}."
    return
  fi

  aws ec2 describe-instances \
    --region "${REGION}" \
    --instance-ids "${INSTANCE_ID}" \
    --query 'Reservations[0].Instances[0].{Id:InstanceId,State:State.Name,PublicIp:PublicIpAddress,PrivateIp:PrivateIpAddress,Subnet:SubnetId,Vpc:VpcId}' \
    --output table

  if [[ "$(aws ec2 describe-instances --region "${REGION}" --instance-ids "${INSTANCE_ID}" --query 'Reservations[0].Instances[0].State.Name' --output text)" == "running" ]]; then
    if aws ssm describe-instance-information --region "${REGION}" --query "InstanceInformationList[?InstanceId=='${INSTANCE_ID}'].PingStatus | [0]" --output text | grep -q '^Online$'; then
      CMD_ID="$(aws ssm send-command \
        --region "${REGION}" \
        --instance-ids "${INSTANCE_ID}" \
        --document-name "AWS-RunShellScript" \
        --parameters 'commands=["docker ps --format \"{{.Names}}: {{.Status}}\"","docker exec tetragon tetra tracingpolicy list || true"]' \
        --query 'Command.CommandId' \
        --output text)"
      aws ssm wait command-executed --region "${REGION}" --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}"
      aws ssm get-command-invocation \
        --region "${REGION}" \
        --command-id "${CMD_ID}" \
        --instance-id "${INSTANCE_ID}" \
        --query 'StandardOutputContent' \
        --output text
    else
      echo "SSM is not online yet."
    fi
  fi
}

destroy_host() {
  INSTANCE_ID="$(get_running_instance_id)"
  if [[ -n "${INSTANCE_ID}" && "${INSTANCE_ID}" != "None" ]]; then
    aws ec2 terminate-instances --region "${REGION}" --instance-ids "${INSTANCE_ID}" >/dev/null
    aws ec2 wait instance-terminated --region "${REGION}" --instance-ids "${INSTANCE_ID}"
    echo "Terminated instance ${INSTANCE_ID}."
  else
    echo "No ${HOST_NAME} instance found."
  fi

  EIP_ALLOC_ID="$(aws ec2 describe-addresses \
    --region "${REGION}" \
    --filters "Name=tag:Name,Values=${EIP_NAME_TAG}" \
    --query 'Addresses[0].AllocationId' \
    --output text)"
  EIP_ASSOC_ID="$(aws ec2 describe-addresses \
    --region "${REGION}" \
    --filters "Name=tag:Name,Values=${EIP_NAME_TAG}" \
    --query 'Addresses[0].AssociationId' \
    --output text)"
  if [[ -n "${EIP_ASSOC_ID}" && "${EIP_ASSOC_ID}" != "None" ]]; then
    aws ec2 disassociate-address --region "${REGION}" --association-id "${EIP_ASSOC_ID}" >/dev/null
  fi
  if [[ -n "${EIP_ALLOC_ID}" && "${EIP_ALLOC_ID}" != "None" ]]; then
    aws ec2 release-address --region "${REGION}" --allocation-id "${EIP_ALLOC_ID}" >/dev/null
    echo "Released EIP ${EIP_ALLOC_ID}."
  fi
}

case "${ACTION}" in
  build)
    echo "== Build runtime defense host =="
    lookup_network
    ensure_iam_role_profile
    ensure_security_group
    lookup_ami_and_db
    launch_or_start_instance
    ensure_eip
    configure_host
    echo ""
    echo "Host ready."
    echo "  Instance ID: ${INSTANCE_ID}"
    echo "  Public URL : http://${PUBLIC_IP}/login.php"
    echo "  Next check : INSTANCE_ID=${INSTANCE_ID} REGION=${REGION} ./runtime-defense-live-check.sh"
    ;;
  status)
    show_status
    ;;
  destroy)
    destroy_host
    ;;
  *)
    echo "Unknown action: ${ACTION}"
    echo "Usage: $0 <build|status|destroy> [--no-eip]"
    exit 1
    ;;
esac
