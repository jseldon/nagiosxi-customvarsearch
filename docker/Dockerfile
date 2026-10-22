# Ubuntu 24.04 with systemd as PID 1 - the Nagios XI installer expects a real init system
FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive container=docker
# unattended-upgrades: XI installs PHP/Apache/MariaDB inside the running container (not in this image),
# so security fixes (e.g. php2shell, CVE-2026-109618/109619) must be applied in the container by its own apt timers.
RUN apt-get update && apt-get upgrade -y \
 && apt-get install -y --no-install-recommends \
      systemd systemd-sysv dbus wget curl ca-certificates tar gzip procps lsb-release iproute2 sudo unattended-upgrades \
 && printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades \
 && apt-get clean && rm -rf /var/lib/apt/lists/* \
 && rm -f /lib/systemd/system/multi-user.target.wants/getty* \
          /lib/systemd/system/sysinit.target.wants/systemd-firstboot.service \
          /lib/systemd/system/sysinit.target.wants/systemd-udevd* \
 && systemctl mask systemd-udevd.service systemd-udevd-kernel.socket systemd-udevd-control.socket getty.target
STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
