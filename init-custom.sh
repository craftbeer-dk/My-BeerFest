#!/bin/sh
# Creates the custom/ override directory with template files.
# Run once after cloning. Files in custom/ are gitignored and
# mounted into the containers at /var/www/html/custom/.
#
# To customize: edit the CSS variables in custom/theme.css,
# or drop replacement icon-192.png / icon-512.png into custom/.

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DEST="$SCRIPT_DIR/custom"

mkdir -p "$DEST" && chmod 755 "$DEST"

# Copy default theme as a starting point for customization
if [ ! -f "$DEST/theme.css" ]; then
  cp "$SCRIPT_DIR/src/config/theme.css" "$DEST/theme.css"
  chmod 644 "$DEST/theme.css"
  echo "Created custom/theme.css (edit to customize theme colors)"
else
  echo "Skipped custom/theme.css (already exists)"
fi

# Ensure all files in custom/ are readable by the nginx container
chmod 755 "$DEST"
chmod 644 "$DEST"/* 2>/dev/null

# Remind about icon overrides
echo ""
echo "To override PWA icons, place these files in custom/:"
echo "  custom/icon-192.png  (192x192)"
echo "  custom/icon-512.png  (512x512)"
echo ""
echo "Done. Rebuild containers to apply: docker compose up -d --build"
