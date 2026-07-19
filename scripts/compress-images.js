import sharp from 'sharp';
import fs from 'fs';
import path from 'path';

// Get files from arguments
const files = process.argv.slice(2);

if (files.length === 0) {
  console.log('No image files provided for compression.');
  process.exit(0);
}

const SUPPORTED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.webp'];
const MAX_WIDTH = 1920;

async function compressImage(filePath) {
  try {
    const ext = path.extname(filePath).toLowerCase();
    if (!SUPPORTED_EXTENSIONS.includes(ext)) {
      return;
    }

    const stats = fs.statSync(filePath);
    const originalSize = stats.size;
    
    // Read file into buffer to avoid locking the file on Windows
    const fileBuffer = fs.readFileSync(filePath);

    // Load image metadata
    const image = sharp(fileBuffer);
    const metadata = await image.metadata();

    let pipeline = sharp(fileBuffer);

    // Resize if wider than MAX_WIDTH (1920px)
    if (metadata.width && metadata.width > MAX_WIDTH) {
      pipeline = pipeline.resize({
        width: MAX_WIDTH,
        withoutEnlargement: true,
        fit: 'inside'
      });
    }

    // Apply format-specific optimizations
    if (ext === '.jpg' || ext === '.jpeg') {
      pipeline = pipeline.jpeg({
        quality: 82,
        progressive: true,
        mozjpeg: true
      });
    } else if (ext === '.png') {
      pipeline = pipeline.png({
        compressionLevel: 8,
        progressive: true
      });
    } else if (ext === '.webp') {
      pipeline = pipeline.webp({
        quality: 80
      });
    }

    // Output to a temporary buffer
    const buffer = await pipeline.toBuffer();
    
    // Only overwrite if the compressed version is actually smaller
    if (buffer.length < originalSize) {
      fs.writeFileSync(filePath, buffer);
      const newSize = buffer.length;
      const savings = ((originalSize - newSize) / originalSize * 100).toFixed(1);
      console.log(`  - Optimized image: ${filePath} (${(originalSize / 1024).toFixed(1)} KB -> ${(newSize / 1024).toFixed(1)} KB, -${savings}%)`);
    } else {
      console.log(`  - Image already optimal: ${filePath} (keeping original)`);
    }
  } catch (error) {
    console.error(`  - Failed to compress image ${filePath}:`, error.message);
  }
}

async function run() {
  console.log('Optimizing staged images...');
  for (const file of files) {
    if (fs.existsSync(file)) {
      await compressImage(file);
    }
  }
}

run();
