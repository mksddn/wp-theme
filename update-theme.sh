#!/bin/bash

# Get current version from style.css
CURRENT_VERSION=$(grep "Version:" style.css | sed 's/.*Version: *//' | tr -d ' ')
echo "📦 Current version: $CURRENT_VERSION"
echo ""

# Parse version components
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT_VERSION"

# Show menu
echo "Select version increment:"
echo "  1) Patch  ($MAJOR.$MINOR.$((PATCH + 1)))"
echo "  2) Minor  ($MAJOR.$((MINOR + 1)).0)"
echo "  3) Major  ($((MAJOR + 1)).0.0)"
echo ""
read -p "Choose option (1-3): " choice

# Calculate new version based on choice
case $choice in
    1)
        PATCH=$((PATCH + 1))
        ;;
    2)
        MINOR=$((MINOR + 1))
        PATCH=0
        ;;
    3)
        MAJOR=$((MAJOR + 1))
        MINOR=0
        PATCH=0
        ;;
    *)
        echo "❌ Invalid choice. Exiting."
        exit 1
        ;;
esac

NEW_VERSION="$MAJOR.$MINOR.$PATCH"
echo ""
echo "✨ New version: $NEW_VERSION"
echo ""

# Confirm before proceeding
read -p "Proceed with update? (y/n): " confirm
if [[ ! $confirm =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 0
fi

# Update version in style.css
sed -i '' "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/" style.css

# Commit changes
git add .
git commit -m "Bump version to $NEW_VERSION"

# Create and push tag
git tag -a "v$NEW_VERSION" -m "Release version $NEW_VERSION"
git push origin main
git push origin "v$NEW_VERSION"

# Create release via GitHub CLI (if installed and authenticated)
if command -v gh &> /dev/null; then
    # Check if GitHub CLI is authenticated
    if ! gh auth status &> /dev/null; then
        echo "⚠️  GitHub CLI is not authenticated. Please run: gh auth login"
        echo "⚠️  Or set GH_TOKEN environment variable"
        echo ""
        echo "Please create release manually at:"
        echo "https://github.com/mksddn/wp-theme/releases/new"
        echo "Tag: v$NEW_VERSION"
        echo "Title: Release v$NEW_VERSION"
    else
        # Check if release already exists
        if gh release view "v$NEW_VERSION" &> /dev/null; then
            echo "ℹ️  Release v$NEW_VERSION already exists, skipping creation"
        else
            # Create release
            if gh release create "v$NEW_VERSION" \
                --title "Release v$NEW_VERSION" \
                --notes "Theme update to version $NEW_VERSION" \
                --target main 2>&1; then
                echo "✅ Release created successfully!"
            else
                echo "❌ Failed to create release. Please create manually at:"
                echo "https://github.com/mksddn/wp-theme/releases/new"
                echo "Tag: v$NEW_VERSION"
                echo "Title: Release v$NEW_VERSION"
            fi
        fi
    fi
else
    echo "⚠️  GitHub CLI not found. Please create release manually at:"
    echo "https://github.com/mksddn/wp-theme/releases/new"
    echo "Tag: v$NEW_VERSION"
    echo "Title: Release v$NEW_VERSION"
fi

echo "🎉 Version $NEW_VERSION is ready!"