#!/bin/bash

# Получаем текущую версию из style.css
CURRENT_VERSION=$(grep "Version:" style.css | sed 's/.*Version: *//' | tr -d ' ')
echo "Current version: $CURRENT_VERSION"

# Увеличиваем версию (patch)
NEW_VERSION=$(echo $CURRENT_VERSION | awk -F. '{$NF = $NF + 1;} 1' | sed 's/ /./g')
echo "New version: $NEW_VERSION"

# Обновляем версию в style.css
sed -i '' "s/Version: $CURRENT_VERSION/Version: $NEW_VERSION/" style.css

# Коммитим изменения
git add .
git commit -m "Bump version to $NEW_VERSION"

# Создаем и пушим тег
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