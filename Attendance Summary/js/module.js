/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

// Lighten a hex colour by the given percentage.
function lightenColor(color, percent) {
    color = color.replace(/^#/, '');
    var num = parseInt(color, 16);
    var amt = Math.round(2.55 * percent);
    var R = (num >> 16) + amt;
    var G = ((num >> 8) & 0x00FF) + amt;
    var B = (num & 0x0000FF) + amt;
    R = (R < 255) ? R : 255;
    G = (G < 255) ? G : 255;
    B = (B < 255) ? B : 255;
    return '#' + ((1 << 24) + (R << 16) + (G << 8) + B).toString(16).slice(1).toUpperCase();
}

// Compute the hover background colour for an attendance row.
function hoverColor(original) {
    if (original.toUpperCase() === '#FFFFFF') {
        return '#F0F8FF';
    }

    return lightenColor(original, 10);
}

// Toggle a day's detail row in the attendance table.
function toggleDetails(dateStr) {
    var row = document.getElementById('details-' + dateStr);
    if (!row) return;

    row.style.display = (row.style.display === 'none' || row.style.display === '') ? 'table-row' : 'none';
}
