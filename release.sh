PLUGIN_SLUG="yay-automation"
PROJECT_PATH=$(pwd)
BUILD_PATH="${PROJECT_PATH}/release"
DEST_PATH="$BUILD_PATH/$PLUGIN_SLUG"

echo "Generating build directory..."
rm -rf "$BUILD_PATH"
mkdir -p "$DEST_PATH"

echo "Installing JS dependencies..."
pnpm install --frozen-lockfile
echo "Running JS Build..."
pnpm build || exit 1

echo "Syncing files..."
rsync -rc --exclude-from="$PROJECT_PATH/.distignore" "$PROJECT_PATH/" "$DEST_PATH/" --delete --delete-excluded

# Check if tools directory exists before running lint
if [ -d "tools" ]; then
    echo "Run lint"
    cd tools
    composer run cbf ../release
    cd ..
fi

# Remove development code
sed -i "" "/'YAY_AUTOMATION_IS_DEVELOPMENT', true/d" "$DEST_PATH/yay-automation.php"
rm -f "$DEST_PATH/src/Engine/Register/RegisterDev.php"

echo "Generating zip file..."
cd "$BUILD_PATH" || exit
zip -q -r "${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG/"
rm -rf "$PLUGIN_SLUG"
echo "${PLUGIN_SLUG}.zip file generated!"

echo "Build successfully"
