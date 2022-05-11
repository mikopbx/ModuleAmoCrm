#!/bin/sh
#
# MikoPBX - free phone system for small business
# Copyright © 2017-2022 Alexey Portnov and Nikolay Beketov
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along with this program.
# If not, see <https://www.gnu.org/licenses/>.
#

dir="$(cd "$(dirname "$0")" || exit; pwd -P)";

tmpDir="$HOME/$(date +%s)";
mkdir "$tmpDir";
echo "temp dir: $tmpDir";

#############################################
# Build widget

widgetSubDir='/public/assets/widget';
resultName="${dir}/_widget.zip";

rm -rf "$resultName" "${dir}${widgetSubDir}/widget.zip";
cp -r "${dir}${widgetSubDir}"/* "$tmpDir";
rm -rf "${tmpDir}${widgetSubDir}/version";

newVersion="1.0.$(( 1 + $(cut -d '.' -f 3 < "${dir}/_version")))";
echo "$newVersion" > "${dir}/_version";
find "$tmpDir" -type f -name "*.j*" -print0 | xargs -0 sed -i '' "s/%WidgetVersion%/${newVersion}/g"
(cd "$tmpDir" && zip -r -X -q "$resultName" .)

#############################################
# Build module MikoPBX

rm -rf "${tmpDir:?}"/*;
cp -r "${dir}"/* "$tmpDir";
rm -rf "${tmpDir:?}${widgetSubDir}" "${tmpDir}/_"* "$tmpDir/build.sh";

find "$tmpDir" -type f -name "*.j*" -print0 | xargs -0 sed -i '' "s/%ModuleVersion%/${newVersion}/g"

moduleFile="${dir}/_$(basename "${dir}").zip";
rm -rf "$moduleFile";
(cd "$tmpDir" && zip -r -X -q "$moduleFile" .)

#############################################
# Clean temp files

rm -rf "$tmpDir";
