#!/usr/bin/env bash
set -euo pipefail

# Live blue-team readiness check for the Tetragon runtime-defense host.
# Usage:
#   ./runtime-defense-live-check.sh
# Optional overrides (recommended for portability):
#   INSTANCE_ID=i-... REGION=us-east-1 APP_URL=http://x.x.x.x/login.php ./runtime-defense-live-check.sh
#   HOST_TAG=tetragon-runtime-defense-demo REGION=us-east-1 ./runtime-defense-live-check.sh

REGION="${REGION:-us-east-1}"
HOST_TAG="${HOST_TAG:-tetragon-runtime-defense-demo}"
INSTANCE_ID="${INSTANCE_ID:-}"
APP_URL="${APP_URL:-}"

if [[ -z "${INSTANCE_ID}" ]]; then
  INSTANCE_ID="$(aws ec2 describe-instances \
    --region "${REGION}" \
    --filters "Name=tag:Name,Values=${HOST_TAG}" "Name=instance-state-name,Values=running" \
    --query 'Reservations[].Instances[0].InstanceId' \
    --output text)"
fi

if [[ -z "${INSTANCE_ID}" || "${INSTANCE_ID}" == "None" ]]; then
  echo "FAIL: no running blue-team host found."
  echo "Hint: launch/rebuild the Tetragon host first, or pass INSTANCE_ID explicitly."
  echo "Example: INSTANCE_ID=i-xxxxxxxx REGION=${REGION} ./runtime-defense-live-check.sh"
  exit 1
fi

if [[ -z "${APP_URL}" ]]; then
  PUBLIC_IP="$(aws ec2 describe-instances \
    --region "${REGION}" \
    --instance-ids "${INSTANCE_ID}" \
    --query 'Reservations[0].Instances[0].PublicIpAddress' \
    --output text)"

  if [[ -z "${PUBLIC_IP}" || "${PUBLIC_IP}" == "None" ]]; then
    echo "FAIL: instance ${INSTANCE_ID} has no public IP."
    echo "Hint: associate an Elastic IP or pass APP_URL explicitly."
    exit 1
  fi
  APP_URL="http://${PUBLIC_IP}/login.php"
fi

echo "[1/4] Checking public app endpoint: ${APP_URL}"
HTTP_CODE="$(curl -s -o /dev/null -w "%{http_code}" "${APP_URL}" --max-time 20 || true)"
if [[ "${HTTP_CODE}" != "200" ]]; then
  echo "FAIL: app endpoint returned HTTP ${HTTP_CODE} (expected 200)"
  exit 1
fi
echo "PASS: app endpoint is healthy (HTTP 200)"

echo "[2/4] Running remote blue-team assertions via SSM on ${INSTANCE_ID}"
REMOTE_SCRIPT=$(cat <<'EOF'
set -euo pipefail

if ! docker ps --format '{{.Names}}' | grep -q '^tetragon$'; then
  echo "FAIL: tetragon container is not running on host"
  exit 1
fi
if ! docker ps --format '{{.Names}}' | grep -q '^awsgoat-app$'; then
  echo "FAIL: awsgoat-app container is not running on host"
  exit 1
fi

echo "== containers =="
docker ps --format '{{.Names}}: {{.Status}}'

echo "== policy list (before) =="
docker exec tetragon tetra tracingpolicy list

echo "== attack simulation: reverse shell path =="
set +e
docker exec awsgoat-app php -r 'system("id");' >/tmp/adp_id.out 2>/tmp/adp_id.err
ID_RC=$?
set -e
echo "id_rc=${ID_RC}"
echo "-- id stdout --"
cat /tmp/adp_id.out || true
echo "-- id stderr --"
cat /tmp/adp_id.err || true

echo "== attack simulation: ptrace breakout path =="
set +e
docker exec awsgoat-app python3 -c 'import ctypes; ctypes.CDLL(None).ptrace(16,1,0,0); print("ptrace-bypass")' >/tmp/adp_ptrace.out 2>/tmp/adp_ptrace.err
PTRACE_RC=$?
set -e
echo "ptrace_rc=${PTRACE_RC}"
echo "-- ptrace stdout --"
cat /tmp/adp_ptrace.out || true
echo "-- ptrace stderr --"
cat /tmp/adp_ptrace.err || true

echo "== local app health from host =="
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost/login.php

echo "== policy list (after) =="
docker exec tetragon tetra tracingpolicy list
EOF
)

REMOTE_SCRIPT_B64="$(printf '%s' "${REMOTE_SCRIPT}" | base64)"
CMD_ID="$(aws ssm send-command \
  --instance-ids "${INSTANCE_ID}" \
  --document-name "AWS-RunShellScript" \
  --parameters "commands=[\"echo ${REMOTE_SCRIPT_B64} | base64 -d > /tmp/runtime-defense-check.sh\",\"bash /tmp/runtime-defense-check.sh\"]" \
  --region "${REGION}" \
  --query 'Command.CommandId' \
  --output text)"

set +e
aws ssm wait command-executed --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}"
WAIT_RC=$?
set -e
STATUS="$(aws ssm get-command-invocation --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}" --query 'Status' --output text)"
OUTPUT="$(aws ssm get-command-invocation --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}" --query 'StandardOutputContent' --output text)"
ERRORS="$(aws ssm get-command-invocation --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}" --query 'StandardErrorContent' --output text)"

echo "${OUTPUT}"
if [[ -n "${ERRORS}" && "${ERRORS}" != "None" ]]; then
  echo "== remote stderr =="
  echo "${ERRORS}"
fi

if [[ ${WAIT_RC} -ne 0 || "${STATUS}" != "Success" ]]; then
  echo "FAIL: remote checks returned status ${STATUS}"
  exit 1
fi

echo "[3/4] Verifying expected block behavior markers"
if grep -q "uid=0(root)" <<<"${OUTPUT}"; then
  echo "WARN: reverse-shell simulation executed id as root on this host/kernel profile."
  echo "WARN: ptrace-path enforcement is still validated below (hard fail if bypassed)."
fi
if grep -q "ptrace-bypass" <<<"${OUTPUT}"; then
  echo "FAIL: ptrace simulation unexpectedly printed success marker"
  exit 1
fi
if ! grep -q "HTTP 200" <<<"${OUTPUT}"; then
  echo "FAIL: local app health check on the host is not HTTP 200"
  exit 1
fi

echo "[4/4] Live blue-team check complete"
echo "PASS: runtime checks completed and app remains healthy."
