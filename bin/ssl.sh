#!/bin/bash

. /tmp/.env

if [[ ! $(command -v mkcert) ]]; then
  echo "Installing mkcert"
  apt-get -qq update && apt -qq install -y wget libnss3-tools

  # Download the mkcert build that matches the container's architecture.
  case "$(uname -m)" in
    x86_64) MKCERT_ARCH=amd64 ;;
    *) MKCERT_ARCH=arm64 ;;
  esac

  LATEST_RELEASE_DOWNLOAD_URL=$(curl -s "https://api.github.com/repos/FiloSottile/mkcert/releases" | jq -r --arg arch "linux-${MKCERT_ARCH}" 'first | .assets[] | select(.name | contains($arch)) | .browser_download_url')
  printf "$LATEST_RELEASE_DOWNLOAD_URL"

  wget -q "$LATEST_RELEASE_DOWNLOAD_URL"
  mv mkcert-v*-linux-"${MKCERT_ARCH}" mkcert
  chmod a+x mkcert
  mv mkcert /usr/local/bin/
fi

CERTS_DIR="/etc/ssl/certs"

DOMAIN=$1
REPLACE_DOMAIN=false

if [ "$DOMAIN" == "localhost" ]; then
  DOMAIN=$WP_DOMAIN
  REPLACE_DOMAIN=true
fi

echo "Creating SSL certificate in $CERTS_DIR for ${DOMAIN}…"

if [ -f "$CERTS_DIR/${DOMAIN}.pem" ]; then
  echo "Found SSL certificate in $CERTS_DIR for ${DOMAIN}."
else
  mkdir -p $CERTS_DIR
  cd $CERTS_DIR
  # Create certificate for the domain.
  mkcert ${DOMAIN}
fi

# Create the local CA.
if [ ! -f "$(mkcert -CAROOT)/rootCA.pem" ]; then
  mkcert -install
fi

if [ "$REPLACE_DOMAIN" == true ]; then
  # Replace "localhost" with WP_DOMAIN in the apache config file:
  sed -i "s/localhost/${WP_DOMAIN}/g" /etc/apache2/sites-available/000-default.conf
fi

