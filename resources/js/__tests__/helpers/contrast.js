// WCAG 2.x contrast ratio between two #rrggbb colours (used by design-tokens.test.js, not bundled).

/**
 * @param {string} hex  "#rrggbb"
 * @returns {number}  relative luminance, 0 (black) to 1 (white)
 */
export function relativeLuminance(hex) {
    const [red, green, blue] = [1, 3, 5]
        .map((index) => parseInt(hex.slice(index, index + 2), 16) / 255)
        .map((value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));

    return 0.2126 * red + 0.7152 * green + 0.0722 * blue;
}

/**
 * @param {string} hexA
 * @param {string} hexB
 * @returns {number}  1 to 21
 */
export function contrastRatio(hexA, hexB) {
    const [lighter, darker] = [relativeLuminance(hexA), relativeLuminance(hexB)].sort((a, b) => b - a);

    return (lighter + 0.05) / (darker + 0.05);
}
