#!/bin/bash
# Runtime defense demo: Cilium Tetragon blocking malicious behavior in the
# kernel while the app keeps serving traffic.
#
# Infra (not managed by this repo's Terraform, provisioned separately for
# this demo):
#   Instance:    i-0f0d60766006a7901 (tetragon-runtime-defense-demo)
#   Public IP:   13.220.150.108          -> http://13.220.150.108/login.php
#   App:         awsgoat-app container, same DB as the main AWSGoat env
#   Defense:     tetragon container, two enforcing TracingPolicies:
#                  - block-reverse-shell   (execve of /bin/sh, /bin/bash, /bin/dash)
#                  - block-ptrace-breakout (any ptrace syscall)
#
# No SSH key is set up; everything below runs through SSM Session Manager,
# same as the rest of this project.
#
# IMPORTANT: this instance lives inside module-2's own VPC (to reuse its
# security group for RDS access), so a `terraform destroy` of module-2 WILL
# take this instance down too (VPC deletion force-terminates everything in
# it). If you destroy/redeploy module-2 again, re-run the setup steps this
# script's history documents (new AMI lookup, new SG in the new VPC reusing
# the fresh ECS security group ID, relaunch, reinstall Tetragon + policies)
# rather than expecting this instance to survive.
#
# ---------------------------------------------------------------------------
# STAGE SETUP (do this before the talk, not live):
#   1. Open TWO terminals, in each run:
#        aws ssm start-session --target i-0f0d60766006a7901 --region us-east-1
#   2. Terminal A ("Blue team" / defender view) - stream live kernel events:
#        sudo docker exec -it tetragon tetra getevents -o compact
#      Leave this running and visible throughout the demo.
#   3. Terminal B ("Red team" / attacker view) - used to trigger the "attack".
#
# ---------------------------------------------------------------------------
# LIVE DEMO SCRIPT (run these one at a time in Terminal B, narrate in between):
#
# 1) Show the app is a normal, healthy, live website:
#      curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost/login.php
#
# 2) Show the exploit working with defenses OFF (mirrors the earlier RCE demo:
#    attacker uploaded a PHP file, it runs system() commands freely):
#      sudo docker exec tetragon tetra tracingpolicy disable block-reverse-shell
#      sudo docker exec awsgoat-app php -r 'system("id");'
#    -> prints uid=0(root) gid=0(root)... : the "attacker" got code execution.
#
# 3) Arm the kernel-level defense and repeat the EXACT same attack:
#      sudo docker exec tetragon tetra tracingpolicy enable block-reverse-shell
#      sudo docker exec awsgoat-app php -r 'system("id");'
#    -> prints nothing. Point at Terminal A: it shows the execve syscall
#       getting intercepted and the process SIGKILL'd before /bin/sh ever
#       started - the exact `id` shell/process never spawns this time.
#
# 4) Prove the app itself never noticed:
#      curl -s -o /dev/null -w "HTTP %{http_code}\n" http://localhost/login.php
#    -> still HTTP 200. Only the malicious process died; the site never blinked.
#
# 5) (Optional, defense-in-depth) Same idea for the container-breakout path
#    from the "ECS Container Breakout" attack card (SYS_PTRACE):
#      sudo docker exec awsgoat-app python3 -c \
#        "import ctypes; ctypes.CDLL(None).ptrace(16,1,0,0); print('should not print')"
#    -> "should not print" never prints; Terminal A shows the ptrace syscall
#       overridden and the process killed.
#
# ---------------------------------------------------------------------------
# RESET BETWEEN REHEARSALS (policies stay enabled; nothing to clean up on the
# app side since nothing malicious ever actually ran):
#   sudo docker exec tetragon tetra tracingpolicy list
#
# ---------------------------------------------------------------------------
# TEARDOWN (run from your laptop, after the talk - this instance/SG are NOT
# part of the Terraform state, so `terraform destroy` of module-2 will force
# -terminate the instance via VPC deletion, but the SG and IAM role/profile
# below can outlive that and should still be cleaned up explicitly):
#   aws ec2 terminate-instances --instance-ids i-0f0d60766006a7901 --region us-east-1
#   aws ec2 delete-security-group --group-id sg-004f114c7380a6052 --region us-east-1
#   aws iam remove-role-from-instance-profile --instance-profile-name tetragon-demo-profile --role-name tetragon-demo-role
#   aws iam delete-instance-profile --instance-profile-name tetragon-demo-profile
#   aws iam detach-role-policy --role-name tetragon-demo-role --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
#   aws iam detach-role-policy --role-name tetragon-demo-role --policy-arn arn:aws:iam::aws:policy/AmazonEC2ContainerRegistryReadOnly
#   aws iam delete-role --role-name tetragon-demo-role
