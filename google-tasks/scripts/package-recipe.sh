#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT_DIR}/dist"
ZIP_PATH="${OUT_DIR}/google-tasks-recipe.zip"

mkdir -p "${OUT_DIR}"
rm -f "${ZIP_PATH}"

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT

mkdir -p "${tmp_dir}/src"
cp "${ROOT_DIR}/recipe/settings.yml" "${tmp_dir}/src/settings.yml"
cp "${ROOT_DIR}/recipe/full.liquid" "${tmp_dir}/src/full.liquid"

(cd "${tmp_dir}" && zip -qr "${ZIP_PATH}" src)

echo "${ZIP_PATH}"
