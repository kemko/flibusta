FROM ubuntu:22.04

ARG CALIBRE_VERSION=5.37.0+dfsg-1build1

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends calibre=${CALIBRE_VERSION} libxml2-utils php-cli php-xml \
	&& rm -rf /var/lib/apt/lists/*

COPY application/tools/epub_to_fb2.py /opt/flibusta/epub_to_fb2.py

ENTRYPOINT ["calibre-debug", "-e", "/opt/flibusta/epub_to_fb2.py", "--"]
