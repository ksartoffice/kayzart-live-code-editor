#!/usr/bin/env bash

set -e

if [ $# -lt 3 ]; then
  echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]"
  exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_TESTS_DIR=${WP_TESTS_DIR-$PROJECT_ROOT/.wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$PROJECT_ROOT/.wordpress}

download() {
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 5 --retry-all-errors --retry-delay 2 "$1" -o "$2"
  elif command -v wget >/dev/null 2>&1; then
    wget -q --tries=5 --waitretry=2 --retry-connrefused -O "$2" "$1"
  else
    echo "curl or wget is required."
    exit 1
  fi
}

is_valid_zip() {
  local zip_path=$1

  if [ ! -f "$zip_path" ]; then
    return 1
  fi

  if command -v unzip >/dev/null 2>&1; then
    unzip -tq "$zip_path" >/dev/null 2>&1
    return
  fi

  if command -v python >/dev/null 2>&1; then
    python - <<PY
import sys
import zipfile
path = r"$zip_path"
if not zipfile.is_zipfile(path):
    sys.exit(1)
with zipfile.ZipFile(path, "r") as zf:
    bad = zf.testzip()
sys.exit(0 if bad is None else 1)
PY
    return
  fi

  return 1
}

print_download_preview() {
  local path=$1
  if [ ! -f "$path" ]; then
    return
  fi

  echo "Download preview (first 5 lines):"
  head -n 5 "$path" || true
}

extract_zip() {
  local zip_path=$1
  local dest_dir=$2

  if command -v unzip >/dev/null 2>&1; then
    unzip -q -o "$zip_path" -d "$dest_dir"
    return
  fi

  if command -v python >/dev/null 2>&1; then
    python - <<PY
import zipfile
zipfile.ZipFile(r"$zip_path").extractall(r"$dest_dir")
PY
    return
  fi

  echo "unzip or python is required to extract $zip_path"
  exit 1
}

install_wp() {
  local archive_name='latest'

  if [ "$WP_VERSION" = "latest" ]; then
    archive_name='latest'
  elif [[ "$WP_VERSION" =~ [0-9]+\.[0-9]+(\.[0-9]+)? ]]; then
    archive_name="wordpress-$WP_VERSION"
  elif [ "$WP_VERSION" = "nightly" ] || [ "$WP_VERSION" = "trunk" ]; then
    archive_name='nightly'
  else
    archive_name="wordpress-$WP_VERSION"
  fi

  if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/${archive_name}.tar.gz" "$TMPDIR/wordpress.tar.gz"
    tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
  fi

  if [ ! -f "$WP_CORE_DIR/wp-config.php" ]; then
    # The core tarball already ships wp-config-sample.php, so prefer the local
    # copy. Falling back to raw.githubusercontent requires a real git ref, and
    # "latest"/"nightly"/"trunk" are not refs (they 404), so map them to master.
    if [ -f "$WP_CORE_DIR/wp-config-sample.php" ]; then
      cp "$WP_CORE_DIR/wp-config-sample.php" "$WP_CORE_DIR/wp-config.php"
    else
      local config_ref="$WP_VERSION"
      if [ "$WP_VERSION" = "latest" ] || [ "$WP_VERSION" = "nightly" ] || [ "$WP_VERSION" = "trunk" ]; then
        config_ref='master'
      fi
      download "https://raw.githubusercontent.com/WordPress/WordPress/$config_ref/wp-config-sample.php" "$WP_CORE_DIR/wp-config.php"
    fi
    sed -i.bak "s/database_name_here/$DB_NAME/" "$WP_CORE_DIR/wp-config.php"
    sed -i.bak "s/username_here/$DB_USER/" "$WP_CORE_DIR/wp-config.php"
    sed -i.bak "s/password_here/$DB_PASS/" "$WP_CORE_DIR/wp-config.php"
    sed -i.bak "s/localhost/$DB_HOST/" "$WP_CORE_DIR/wp-config.php"
  fi
}

install_test_suite() {
  local tests_marker="$WP_TESTS_DIR/includes/class-basic-object.php"
  local tests_config="$WP_TESTS_DIR/wp-tests-config.php"
  if [ -f "$tests_marker" ] && [ -f "$tests_config" ]; then
    return
  fi

  rm -rf "$WP_TESTS_DIR"
  mkdir -p "$WP_TESTS_DIR"

  local tests_zip="$TMPDIR/wordpress-develop-tests.zip"
  local extract_dir="$TMPDIR/wordpress-develop-tests"

  local urls=()
  if [ "$WP_VERSION" = "latest" ] || [ "$WP_VERSION" = "nightly" ] || [ "$WP_VERSION" = "trunk" ]; then
    urls+=("https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip")
  else
    urls+=("https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.zip")
    if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
      urls+=("https://github.com/WordPress/wordpress-develop/archive/refs/tags/$WP_VERSION.0.zip")
    fi
    urls+=("https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.zip")
  fi

  local downloaded=false
  for url in "${urls[@]}"; do
    echo "Downloading WordPress develop tests from: $url"
    if download "$url" "$tests_zip"; then
      if is_valid_zip "$tests_zip"; then
        downloaded=true
        break
      fi

      echo "Downloaded file is not a valid zip: $url"
      print_download_preview "$tests_zip"
      rm -f "$tests_zip"
    else
      echo "Download failed from: $url"
    fi
  done

  if [ "$downloaded" != "true" ]; then
    echo "Failed to download WordPress develop tests."
    exit 1
  fi

  rm -rf "$extract_dir"
  mkdir -p "$extract_dir"
  extract_zip "$tests_zip" "$extract_dir"

  local root_dir=""
  for dir in "$extract_dir"/wordpress-develop*; do
    if [ -d "$dir/tests/phpunit" ]; then
      root_dir="$dir"
      break
    fi
  done

  if [ -z "$root_dir" ] && [ -d "$extract_dir/tests/phpunit" ]; then
    root_dir="$extract_dir"
  fi

  if [ -z "$root_dir" ] || [ ! -d "$root_dir/tests/phpunit" ]; then
    echo "tests/phpunit not found in tests archive."
    exit 1
  fi

  cp -R "$root_dir/tests/phpunit/." "$WP_TESTS_DIR"

  if [ ! -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ] && [ -f "$root_dir/wp-tests-config-sample.php" ]; then
    cp "$root_dir/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
  fi

  if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ] && [ -f "$WP_TESTS_DIR/wp-tests-config-sample.php" ]; then
    cp "$WP_TESTS_DIR/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
  fi

  if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
    echo "wp-tests-config.php is missing after test suite install."
    exit 1
  fi

  WP_CORE_DIR="${WP_CORE_DIR%/}/"
  sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s/localhost/$DB_HOST/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s|define( 'ABSPATH'.*|define( 'ABSPATH', '$WP_CORE_DIR' );|" "$WP_TESTS_DIR/wp-tests-config.php"
}

install_db() {
  if [ "$SKIP_DB_CREATE" = "true" ]; then
    return
  fi

  if command -v mysqladmin >/dev/null 2>&1; then
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
  fi
}

install_wp
install_test_suite
install_db

echo "WP core: $WP_CORE_DIR"
echo "WP tests: $WP_TESTS_DIR"
