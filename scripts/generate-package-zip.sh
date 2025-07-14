#!/bin/bash

# Define constants
PLUGIN_SLUG="woo-satispay"
BUILD_TEMP_PARENT_DIR="./build" # A temporary directory in the current working directory
BUILD_DIR="${BUILD_TEMP_PARENT_DIR}/${PLUGIN_SLUG}"
ZIP_FILE="${PLUGIN_SLUG}.zip"

# --- Start of Build Process ---

echo "Starting plugin build for ${PLUGIN_SLUG}..."

# Clean up previous build artifacts
echo "Cleaning up previous build artifacts..."
rm -rf "${BUILD_TEMP_PARENT_DIR}"
rm -f "${ZIP_FILE}"

# Create the temporary build directory structure
echo "Creating temporary build directory: ${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"

# List of files/directories to copy into the distribution package
# This explicit list is good for WordPress plugins to control what goes in.
FILES_TO_COPY=(
    "woo-satispay.pot"
    "woo-satispay.php"
    "wc-satispay.php"
    "logo.svg"
    # ".gitignore" # Exclude .gitignore from distribution
    "LICENSE"
    "readme.txt"
    "satispay-sdk"
    "assets"
    "includes"
    "resources"
)

echo "Copying files to ${BUILD_DIR}..."
for item in "${FILES_TO_COPY[@]}"; do
    if [ -e "$item" ]; then # Check if the item exists before copying
        cp -R "$item" "${BUILD_DIR}/"
    else
        echo "Warning: Source item '$item' not found, skipping."
    fi
done

# Clean up .DS_Store files within the build directory
echo "Removing .DS_Store files from ${BUILD_DIR}..."
find "${BUILD_DIR}" -name ".DS_Store" -delete

# Change to the parent of the build directory to create the zip correctly
# This mimics the original script's behavior for the zip command
echo "Creating zip archive: ${ZIP_FILE}..."
cd "${BUILD_TEMP_PARENT_DIR}"
zip -r "../${ZIP_FILE}" "${PLUGIN_SLUG}" \
    -x "*/.git/*" \
    -x "*__MACOSX*" \
    -x "*.DS_Store" # Explicitly exclude .DS_Store if any slipped through or for future safety
cd .. # Go back to the original working directory

# Clean up the temporary build directory
echo "Cleaning up temporary build directory..."
rm -rf "${BUILD_TEMP_PARENT_DIR}"

echo "Plugin '${ZIP_FILE}' created successfully in $(pwd)/${ZIP_FILE}"