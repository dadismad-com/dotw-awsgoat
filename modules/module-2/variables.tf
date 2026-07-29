variable "enable_custom_domain" {
  description = "Whether to manage custom domain DNS + ACM + ALB HTTPS listener."
  type        = bool
  default     = true
}

variable "hosted_zone_name" {
  description = "Route53 public hosted zone name."
  type        = string
  default     = "nocontrol.dev."
}

variable "app_domain_name" {
  description = "Primary app domain that should alias to the ALB."
  type        = string
  default     = "awsgoat.nocontrol.dev"
}

variable "create_blue_dns_records" {
  description = "Whether to create A records (e.g. defense/blue subdomains) pointing to the blue-team host IP."
  type        = bool
  default     = true
}

variable "blue_host_ip" {
  description = "Public IPv4 of the blue-team host (EIP recommended). Leave empty to skip blue record creation."
  type        = string
  default     = "44.194.67.73"
}

variable "blue_subdomains" {
  description = "Subdomain labels for blue-team endpoint records."
  type        = list(string)
  default     = ["defense", "blue"]
}
