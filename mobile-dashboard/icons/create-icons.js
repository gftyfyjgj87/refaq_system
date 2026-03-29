// ===== Icon Generator for PWA =====
// This script creates all required PWA icons programmatically

const fs = require('fs');
const { createCanvas } = require('canvas');

// Icon sizes required for PWA
const ICON_SIZES = [16, 32, 72, 96, 128, 144, 152, 192, 384, 512];

// Colors
const COLORS = {
    primary: '#4ECDC4',
    primaryDark: '#2BB3AA',
    secondary: '#44A08D',
    white: '#FFFFFF'
};

function createIcon(size) {
    const canvas = createCanvas(size, size);
    const ctx = canvas.getContext('2d');
    
    // Clear canvas
    ctx.clearRect(0, 0, size, size);
    
    // Create gradient background
    const gradient = ctx.createLinearGradient(0, 0, size, size);
    gradient.addColorStop(0, COLORS.primary);
    gradient.addColorStop(1, COLORS.secondary);
    
    // Draw background circle with padding
    const radius = (size / 2) - 4;
    const center = size / 2;
    
    ctx.beginPath();
    ctx.arc(center, center, radius, 0, 2 * Math.PI);
    ctx.fillStyle = gradient;
    ctx.fill();
    
    // Add subtle border
    ctx.strokeStyle = COLORS.primaryDark;
    ctx.lineWidth = Math.max(1, size / 50);
    ctx.stroke();
    
    // Calculate scale for building elements
    const scale = size / 100;
    
    // Draw building base
    const buildingWidth = 50 * scale;
    const buildingHeight = 40 * scale;
    const buildingX = (size - buildingWidth) / 2;
    const buildingY = size * 0.35;
    
    ctx.fillStyle = COLORS.white;
    ctx.fillRect(buildingX, buildingY, buildingWidth, buildingHeight);
    
    // Draw windows in a grid
    const windowSize = Math.max(2, 6 * scale);
    const windowSpacing = Math.max(4, 10 * scale);
    const windowStartX = buildingX + windowSpacing;
    const windowStartY = buildingY + windowSpacing;
    
    ctx.fillStyle = COLORS.primary;
    
    // Top row of windows
    for (let i = 0; i < 4; i++) {
        const x = windowStartX + (i * windowSpacing);
        ctx.fillRect(x, windowStartY, windowSize, windowSize);
    }
    
    // Bottom row of windows
    for (let i = 0; i < 4; i++) {
        const x = windowStartX + (i * windowSpacing);
        const y = windowStartY + windowSpacing + windowSize;
        ctx.fillRect(x, y, windowSize, windowSize);
    }
    
    // Draw entrance door
    const doorWidth = 16 * scale;
    const doorHeight = 12 * scale;
    const doorX = (size - doorWidth) / 2;
    const doorY = buildingY + buildingHeight - doorHeight;
    
    ctx.fillStyle = COLORS.primaryDark;
    ctx.fillRect(doorX, doorY, doorWidth, doorHeight);
    
    // Add small roof detail
    ctx.beginPath();
    ctx.moveTo(buildingX - 2 * scale, buildingY);
    ctx.lineTo(center, buildingY - 8 * scale);
    ctx.lineTo(buildingX + buildingWidth + 2 * scale, buildingY);
    ctx.closePath();
    ctx.fillStyle = COLORS.primaryDark;
    ctx.fill();
    
    return canvas;
}

// Create all icon sizes
function generateAllIcons() {
    console.log('Generating PWA icons...');
    
    // Create icons directory if it doesn't exist
    if (!fs.existsSync('./icons')) {
        fs.mkdirSync('./icons');
    }
    
    ICON_SIZES.forEach(size => {
        console.log(`Creating ${size}x${size} icon...`);
        
        const canvas = createIcon(size);
        const buffer = canvas.toBuffer('image/png');
        
        fs.writeFileSync(`./icons/icon-${size}x${size}.png`, buffer);
        console.log(`✓ Created icon-${size}x${size}.png`);
    });
    
    // Create favicon.ico (32x32)
    const faviconCanvas = createIcon(32);
    const faviconBuffer = faviconCanvas.toBuffer('image/png');
    fs.writeFileSync('./icons/favicon-32x32.png', faviconBuffer);
    fs.writeFileSync('./icons/favicon.png', faviconBuffer);
    
    // Create Apple touch icon (180x180)
    const appleCanvas = createIcon(180);
    const appleBuffer = appleCanvas.toBuffer('image/png');
    fs.writeFileSync('./icons/apple-touch-icon.png', appleBuffer);
    
    console.log('✅ All PWA icons generated successfully!');
    console.log('\nGenerated files:');
    ICON_SIZES.forEach(size => {
        console.log(`- icon-${size}x${size}.png`);
    });
    console.log('- favicon-32x32.png');
    console.log('- favicon.png');
    console.log('- apple-touch-icon.png');
}

// Run if called directly
if (require.main === module) {
    try {
        generateAllIcons();
    } catch (error) {
        console.error('Error generating icons:', error.message);
        console.log('\nTo use this script, install canvas package:');
        console.log('npm install canvas');
        
        // Fallback: create simple base64 icons
        console.log('\nCreating fallback base64 icons...');
        createBase64Icons();
    }
}

// Fallback function to create simple base64 icons
function createBase64Icons() {
    // Simple SVG icon as base64
    const svgIcon = `
        <svg width="192" height="192" viewBox="0 0 192 192" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#4ECDC4;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#44A08D;stop-opacity:1" />
                </linearGradient>
            </defs>
            <circle cx="96" cy="96" r="88" fill="url(#grad)" stroke="#2BB3AA" stroke-width="4"/>
            <rect x="48" y="67" width="96" height="77" fill="white" rx="6"/>
            <rect x="58" y="77" width="15" height="15" fill="#4ECDC4"/>
            <rect x="81" y="77" width="15" height="15" fill="#4ECDC4"/>
            <rect x="104" y="77" width="15" height="15" fill="#4ECDC4"/>
            <rect x="127" y="77" width="15" height="15" fill="#4ECDC4"/>
            <rect x="58" y="100" width="15" height="15" fill="#4ECDC4"/>
            <rect x="81" y="100" width="15" height="15" fill="#4ECDC4"/>
            <rect x="104" y="100" width="15" height="15" fill="#4ECDC4"/>
            <rect x="127" y="100" width="15" height="15" fill="#4ECDC4"/>
            <rect x="81" y="123" width="30" height="21" fill="#2BB3AA"/>
        </svg>
    `;
    
    const base64Icon = Buffer.from(svgIcon).toString('base64');
    
    // Create a simple HTML file to convert SVG to PNG
    const htmlContent = `
<!DOCTYPE html>
<html>
<head>
    <title>Icon Converter</title>
</head>
<body>
    <canvas id="canvas" width="192" height="192" style="border: 1px solid #ccc;"></canvas>
    <br><br>
    <button onclick="downloadIcon(72)">Download 72x72</button>
    <button onclick="downloadIcon(96)">Download 96x96</button>
    <button onclick="downloadIcon(128)">Download 128x128</button>
    <button onclick="downloadIcon(144)">Download 144x144</button>
    <button onclick="downloadIcon(152)">Download 152x152</button>
    <button onclick="downloadIcon(192)">Download 192x192</button>
    <button onclick="downloadIcon(384)">Download 384x384</button>
    <button onclick="downloadIcon(512)">Download 512x512</button>
    
    <script>
        const svgString = \`${svgIcon}\`;
        
        function downloadIcon(size) {
            const canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            const ctx = canvas.getContext('2d');
            
            const img = new Image();
            img.onload = function() {
                ctx.drawImage(img, 0, 0, size, size);
                
                const link = document.createElement('a');
                link.download = \`icon-\${size}x\${size}.png\`;
                link.href = canvas.toDataURL();
                link.click();
            };
            
            const blob = new Blob([svgString], {type: 'image/svg+xml'});
            const url = URL.createObjectURL(blob);
            img.src = url;
        }
        
        // Draw preview
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        const img = new Image();
        img.onload = function() {
            ctx.drawImage(img, 0, 0, 192, 192);
        };
        const blob = new Blob([svgString], {type: 'image/svg+xml'});
        const url = URL.createObjectURL(blob);
        img.src = url;
    </script>
</body>
</html>
    `;
    
    fs.writeFileSync('./icons/icon-converter.html', htmlContent);
    console.log('✅ Created icon-converter.html');
    console.log('Open this file in a browser to download PNG icons');
}

module.exports = { generateAllIcons, createIcon };