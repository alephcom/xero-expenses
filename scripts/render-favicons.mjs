import fs from 'fs';
import { Resvg } from '@resvg/resvg-js';
import { fileURLToPath } from 'url';
import path from 'path';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const svgPath = path.join(root, 'public', 'favicon-raster.svg');
const svg = fs.readFileSync(svgPath);

function writePng(width, relativeOut) {
  const resvg = new Resvg(svg, {
    fitTo: { mode: 'width', value: width },
    background: 'rgba(0,0,0,0)',
  });
  const data = resvg.render();
  fs.writeFileSync(path.join(root, 'public', relativeOut), data.asPng());
}

/** PNG-embedded ICO (Vista+). */
function pngIhdrDimensions(png) {
  if (png.readUInt32BE(12) !== 0x49484452) {
    throw new Error('Expected PNG IHDR chunk');
  }
  return { w: png.readUInt32BE(16), h: png.readUInt32BE(20) };
}

function createIcoFromPngs(pngBuffers) {
  const n = pngBuffers.length;
  const headerSize = 6 + 16 * n;
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2);
  header.writeUInt16LE(n, 4);

  const entries = [];
  let dataOffset = headerSize;
  for (const png of pngBuffers) {
    const { w, h } = pngIhdrDimensions(png);
    const entry = Buffer.alloc(16);
    entry.writeUInt8(w >= 256 ? 0 : w, 0);
    entry.writeUInt8(h >= 256 ? 0 : h, 1);
    entry.writeUInt8(0, 2);
    entry.writeUInt8(0, 3);
    entry.writeUInt16LE(1, 4);
    entry.writeUInt16LE(32, 6);
    entry.writeUInt32LE(png.length, 8);
    entry.writeUInt32LE(dataOffset, 12);
    dataOffset += png.length;
    entries.push(entry);
  }

  return Buffer.concat([header, ...entries, ...pngBuffers]);
}

writePng(16, 'favicon-16x16.png');
writePng(32, 'favicon-32x32.png');
writePng(180, 'apple-touch-icon.png');
writePng(192, 'icon-192.png');
writePng(512, 'icon-512.png');

const ico = createIcoFromPngs([
  fs.readFileSync(path.join(root, 'public', 'favicon-16x16.png')),
  fs.readFileSync(path.join(root, 'public', 'favicon-32x32.png')),
]);
fs.writeFileSync(path.join(root, 'public', 'favicon.ico'), ico);
