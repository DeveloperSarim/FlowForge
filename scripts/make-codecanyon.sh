#!/usr/bin/env bash
# Builds everything uploaded to CodeCanyon into dist/codecanyon/:
#   flowforge-codecanyon-<version>.zip  the main file buyers download: install + update zips, extractor,
#                                       Documentation/, Licensing/ and README.txt
#   thumbnail-80x80.png, inline-preview-590x300.png   from codecanyon/assets/
#   screenshots-<version>.zip           the preview gallery, from documentation/assets/img/
# license-server/, AUTHOR-GUIDE.md and the private key never go into any of these.
#
# Usage: bash scripts/make-codecanyon.sh [version]   (passed to make-release.sh)
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
bash "$root/scripts/make-release.sh" "${1:-}"
version="$(tr -d '[:space:]' < "$root/VERSION")"

out="$root/dist/codecanyon"
rm -rf "${out:?}"
mkdir -p "$out"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
pkg="$stage/FlowForge-$version"
mkdir -p "$pkg/Licensing"

echo "→ Packaging the main file"
cp "$root/dist/flowforge-install-$version.zip" "$root/dist/flowforge-update-$version.zip" "$root/dist/extract-flowforge.php" "$pkg/"
cp -R "$root/documentation" "$pkg/Documentation"
# The docs name the current files and version.
sed -i.bak -E "s/flowforge-(install|update)-[0-9]+\.[0-9]+\.[0-9]+\.zip/flowforge-\1-$version.zip/g; s/>v[0-9]+\.[0-9]+\.[0-9]+</>v$version</" "$pkg/Documentation/index.html"
rm "$pkg/Documentation/index.html.bak"
sed "s/{{VERSION}}/$version/g" "$root/codecanyon/README.txt" > "$pkg/README.txt"

{
    echo "FlowForge $version is built with these open-source packages."
    echo
    echo "PHP packages (shipped in vendor/):"
    php -d display_startup_errors=0 -d display_errors=stderr -r '
        $j = json_decode(file_get_contents($argv[1]), true);
        $dev = array_flip($j["dev-package-names"] ?? []);
        foreach ($j["packages"] as $p) {
            if (! isset($dev[$p["name"]])) printf("  %-45s %-14s %s\n", $p["name"], $p["version"], implode(", ", $p["license"] ?? ["see package"]));
        }' "$root/vendor/composer/installed.json"
    echo
    echo "JavaScript packages (bundled in public/build/):"
    npm ls --prefix "$root" --omit=dev --all --parseable 2>/dev/null | tail -n +2 | sort -u | node -e '
        require("fs").readFileSync(0, "utf8").trim().split("\n").forEach((dir) => {
            const p = require(dir + "/package.json");
            console.log("  " + p.name.padEnd(45) + " " + p.version.padEnd(14) + " " + (typeof p.license === "string" ? p.license : "see package"));
        });'
    echo
    echo "Fonts and logos:"
    echo "  Geist, Geist Mono (Vercel)                    SIL Open Font License 1.1"
    echo "  SVG Logos (gilbarbara/logos)                  CC0 1.0"
    echo "  Simple Icons                                  CC0 1.0"
    echo
    echo "App names and logos are trademarks of their owners, used only to identify each integration."
    echo "The full license text of each package is in its folder under vendor/ or on its project page."
} > "$pkg/Licensing/THIRD-PARTY-NOTICES.txt"

(cd "$stage" && zip -qr "$out/flowforge-codecanyon-$version.zip" "FlowForge-$version" -x '*.DS_Store')

echo "→ Copying listing images"
cp "$root/codecanyon/assets/thumbnail-80x80.png" "$root/codecanyon/assets/inline-preview-590x300.png" "$out/"
(cd "$root/documentation/assets/img" && zip -q "$out/screenshots-$version.zip" ./*.png)

echo "✓ CodeCanyon upload set for v$version in dist/codecanyon/"
ls -lh "$out" | awk 'NR > 1 { print "  " $5 "\t" $9 }'
