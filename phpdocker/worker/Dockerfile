FROM ubuntu:26.04

RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends calibre libxml2-utils php-cli php-xml \
	&& rm -rf /var/lib/apt/lists/*

COPY application/tools/epub_to_fb2.py /opt/flibusta/epub_to_fb2.py

ENTRYPOINT ["calibre-debug", "-e", "/opt/flibusta/epub_to_fb2.py", "--"]
