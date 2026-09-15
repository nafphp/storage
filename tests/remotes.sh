#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
client_path=${1:?Pass the path to the streaming naf/client checkout}
run_id="naf-storage-test-$$"
s3_name="$run_id-s3"
dav_name="$run_id-dav"

cleanup() {
    docker rm -fv "$s3_name" "$dav_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT HUP INT TERM

docker run --detach --rm --name "$s3_name" -p 127.0.0.1::9000 --tmpfs /data:rw,size=512m \
    -e MINIO_ROOT_USER=naf-storage-test -e MINIO_ROOT_PASSWORD=naf-storage-test-password \
    quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z server /data >/dev/null
docker run --detach --rm --name "$dav_name" -p 127.0.0.1::8080 --tmpfs /data:rw,size=512m \
    rclone/rclone:1.70.3 serve webdav /data --addr :8080 \
    --user naf-storage-test --pass naf-storage-test-password >/dev/null

NAF_TEST_S3_ENDPOINT="http://$(docker port "$s3_name" 9000/tcp)"
NAF_TEST_DAV_ENDPOINT="http://$(docker port "$dav_name" 8080/tcp)"
export NAF_TEST_S3_ENDPOINT NAF_TEST_DAV_ENDPOINT

attempt=0
until curl --silent --fail "$NAF_TEST_S3_ENDPOINT/minio/health/ready" >/dev/null \
    && curl --silent --fail --user naf-storage-test:naf-storage-test-password "$NAF_TEST_DAV_ENDPOINT/" >/dev/null; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo 'Storage fixture servers did not become ready.' >&2
        exit 1
    fi
    sleep 1
done

APP_ENV=test php tests/remotes.php "$client_path"
