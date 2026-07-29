#!/usr/bin/env bash
set -euo pipefail

# Live blue-team readiness check for the Tetragon runtime-defense host.
# Usage:
#   ./runtime-defense-live-check.sh
# Optional overrides:
#   INSTANCE_ID=i-... REGION=us-east-1 APP_URL=http://13.220.150.108/login.php ./runtime-defense-live-check.sh

INSTANCE_ID="${INSTANCE_ID:-i-0f0d60766006a7901}"
REGION="${REGION:-us-east-1}"
APP_URL="${APP_URL:-http://13.220.150.108/login.php}"

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

sleep 8
STATUS="$(aws ssm get-command-invocation --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}" --query 'Status' --output text)"
OUTPUT="$(aws ssm get-command-invocation --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}" --query 'StandardOutputContent' --output text)"
ERRORS="$(aws ssm get-command-invocation --command-id "${CMD_ID}" --instance-id "${INSTANCE_ID}" --region "${REGION}" --query 'StandardErrorContent' --output text)"

echo "${OUTPUT}"
if [[ -n "${ERRORS}" && "${ERRORS}" != "None" ]]; then
  echo "== remote stderr =="
  echo "${ERRORS}"
fi

if [[ "${STATUS}" != "Success" ]]; then
  echo "FAIL: remote checks returned status ${STATUS}"
  exit 1
fi

echo "[3/4] Verifying expected block behavior markers"
if grep -q "uid=0(root)" <<<"${OUTPUT}"; then
  echo "FAIL: reverse-shell simulation unexpectedly executed id as root"
  exit 1
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
echo "PASS: runtime blocks are active and the app remains healthy."
