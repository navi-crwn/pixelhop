#!/usr/bin/env python3
"""
PixelHop - Color Palette Extractor Engine
Extracts dominant colors from an image using k-means clustering.

Usage: python3 palette_engine.py <input_path> [count]

count: number of colors to extract (default 6, clamped to 4-10)

Outputs JSON only to stdout. All logs go to stderr.
"""

import sys
import os
import json

DEFAULT_COUNT = 6
MIN_COUNT = 4
MAX_COUNT = 10
MAX_RESIZE_PX = 200


def parse_count(raw_count):
    """Parse and clamp the requested palette size."""
    try:
        count = int(raw_count)
    except (TypeError, ValueError):
        count = DEFAULT_COUNT
    return max(MIN_COUNT, min(MAX_COUNT, count))


def downscale_image(img, max_px=MAX_RESIZE_PX):
    """Resize an image so its largest side is <= max_px for fast clustering."""
    width, height = img.size
    largest = max(width, height)
    if largest <= max_px:
        return img
    scale = max_px / float(largest)
    new_size = (max(1, int(round(width * scale))), max(1, int(round(height * scale))))
    return img.resize(new_size)


def pixels_to_array(img):
    """Return a float32 (N, 3) array of RGB samples from an image."""
    import numpy as np

    arr = np.asarray(img.convert('RGB'), dtype=np.uint8).reshape(-1, 3)
    return arr.astype(np.float32)


def cluster_sklearn(samples, count):
    """Cluster with scikit-learn MiniBatchKMeans when available."""
    from sklearn.cluster import MiniBatchKMeans

    model = MiniBatchKMeans(
        n_clusters=count,
        n_init=3,
        random_state=42,
        batch_size=min(1024, len(samples)),
    )
    labels = model.fit_predict(samples)
    centers = model.cluster_centers_
    return labels, centers


def cluster_numpy(samples, count):
    """Fallback k-means implemented with numpy only."""
    import numpy as np

    rng = np.random.default_rng(42)
    n_samples = len(samples)

    # k-means++ style seeding with a simple deterministic fallback.
    centers = [samples[int(rng.integers(0, n_samples))]]
    for _ in range(1, count):
        dists = np.min(
            [np.sum((samples - c.astype(np.float32)) ** 2, axis=1) for c in centers],
            axis=0,
        ).astype(np.float64)
        total = dists.sum()
        if total <= 0:
            centers.append(samples[int(rng.integers(0, n_samples))])
            continue
        probs = dists / total
        centers.append(samples[int(rng.choice(n_samples, p=probs))])
    centers = np.array(centers, dtype=np.float32)

    labels = np.zeros(n_samples, dtype=np.int64)
    for _ in range(10):
        # Assignment step
        dists = np.sum(
            (samples[:, None, :] - centers[None, :, :]) ** 2,
            axis=2,
        )
        new_labels = np.argmin(dists, axis=1)

        # Update step
        new_centers = centers.copy()
        for k in range(count):
            members = samples[new_labels == k]
            if len(members) > 0:
                new_centers[k] = members.mean(axis=0)

        if np.array_equal(new_labels, labels):
            labels = new_labels
            centers = new_centers
            break

        labels = new_labels
        centers = new_centers

    return labels, centers


def rgb_to_hex(rgb):
    """Convert an RGB tuple/list to a #rrggbb hex string."""
    r, g, b = [max(0, min(255, int(round(float(x))))) for x in rgb]
    return '#{:02x}{:02x}{:02x}'.format(r, g, b)


def extract_palette(input_path, count):
    """Extract up to `count` dominant colors from an image."""
    if not os.path.exists(input_path):
        return {
            'success': False,
            'error': 'Input file not found: {}'.format(input_path),
        }

    try:
        from PIL import Image
    except ImportError as e:
        return {
            'success': False,
            'error': 'Pillow not installed: {}'.format(str(e)),
        }

    try:
        import numpy as np
    except ImportError as e:
        return {
            'success': False,
            'error': 'numpy not installed: {}'.format(str(e)),
        }

    try:
        with Image.open(input_path) as img:
            img.load()
            small = downscale_image(img, MAX_RESIZE_PX).convert('RGB')
    except Exception as e:
        return {
            'success': False,
            'error': 'Cannot decode image: {}'.format(str(e)),
        }

    samples = pixels_to_array(small)

    if len(samples) == 0:
        return {
            'success': False,
            'error': 'Image contains no pixels',
        }

    # Fewer unique colors than requested clusters => just return the unique ones.
    unique = np.unique(samples, axis=0)
    if len(unique) <= count:
        centers = unique.astype(np.float32)
        labels = np.argmin(
            np.sum((samples[:, None, :] - centers[None, :, :]) ** 2, axis=2),
            axis=1,
        )
    else:
        try:
            labels, centers = cluster_sklearn(samples, count)
        except ImportError:
            labels, centers = cluster_numpy(samples, count)

    # Build histogram of assigned samples
    counts = np.bincount(labels, minlength=len(centers)).astype(np.float64)
    total = counts.sum()

    palette = []
    for idx in np.argsort(counts)[::-1]:
        cluster_count = int(counts[idx])
        if cluster_count <= 0:
            continue
        rgb = [max(0, min(255, int(round(float(x))))) for x in centers[idx]]
        palette.append({
            'hex': rgb_to_hex(rgb),
            'rgb': rgb,
            'percent': round((cluster_count / total) * 100, 1),
        })

    if not palette:
        return {
            'success': False,
            'error': 'Could not extract any colors from image',
        }

    return {
        'success': True,
        'colors': palette,
    }


def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python3 palette_engine.py <input_path> [count]'
        }))
        sys.exit(1)

    input_path = sys.argv[1]
    count = parse_count(sys.argv[2] if len(sys.argv) > 2 else DEFAULT_COUNT)

    result = extract_palette(input_path, count)
    print(json.dumps(result))


if __name__ == '__main__':
    main()
