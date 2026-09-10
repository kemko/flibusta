FROM ubuntu:22.04@sha256:1cc7bb38a74c0e126716646e47c0b3c5c139547d386d5ff7a64cf3ea316ca523

ARG CALIBRE_VERSION=5.37.0+dfsg-1build1

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends calibre=${CALIBRE_VERSION} libxml2-utils php-cli php-xml \
	&& rm -rf /var/lib/apt/lists/*

COPY application/tools/epub_to_fb2.py /opt/flibusta/epub_to_fb2.py

ENTRYPOINT ["calibre-debug", "-e", "/opt/flibusta/epub_to_fb2.py", "--"]
