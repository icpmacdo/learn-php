# Jenkins controller for the task-tracker quality gate.
#
# Everything is baked in at build time -- plugins from plugins.txt, config
# from casc.yaml -- so `docker compose -f jenkins/compose.yaml up -d` yields
# a fully working Jenkins with the pipeline job defined: zero setup clicks.
FROM jenkins/jenkins:lts-jdk17

USER root

# docker CLI + compose plugin (they talk to the HOST daemon through the
# socket mounted by compose.yaml -- no daemon runs in this image) and rsync
# (the pipeline's workspace snapshot).
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg rsync \
    && install -m 0755 -d /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
        > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends docker-ce-cli docker-compose-plugin \
    && rm -rf /var/lib/apt/lists/*

# Plugins, resolved with their dependencies at image build time.
COPY plugins.txt /usr/share/jenkins/ref/plugins.txt
RUN jenkins-plugin-cli --plugin-file /usr/share/jenkins/ref/plugins.txt

# Configuration-as-code: admin user, authorization, and the seed job that
# defines the task-tracker pipeline.
COPY casc.yaml /usr/share/jenkins/casc.yaml
ENV CASC_JENKINS_CONFIG=/usr/share/jenkins/casc.yaml

# No setup wizard: JCasC is the configuration, there is nothing to click.
ENV JAVA_OPTS="-Djenkins.install.runSetupWizard=false"

# Stays root: the mounted docker socket belongs to the host; running the
# controller as root sidesteps group-id gymnastics. Fine for a local-only
# toy CI, named as such -- production would use an agent with proper perms.
