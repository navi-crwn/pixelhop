#!/usr/bin/env python3
"""
PixelHop - Face Blur Engine (OpenCV Haar cascade)
Blurs or pixelates detected faces for privacy.

Usage: python3 faceblur_engine.py <input_path> <output_path> [method] [strength]

Methods: blur (default), pixelate
Strength: 1-10 (default 5)

Outputs JSON only to stdout. All logs go to stderr.
"""

import sys
import os
import json


def main():
    if len(sys.argv) < 3:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python3 faceblur_engine.py <input_path> <output_path> [method] [strength]'
        }))
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]
    method = sys.argv[3] if len(sys.argv) > 3 else 'blur'
    strength_raw = sys.argv[4] if len(sys.argv) > 4 else '5'

    if method not in ('blur', 'pixelate'):
        method = 'blur'

    try:
        strength = int(strength_raw)
    except (TypeError, ValueError):
        strength = 5

    strength = max(1, min(10, strength))

    if not os.path.exists(input_path):
        print(json.dumps({
            'success': False,
            'error': f'Input file not found: {input_path}'
        }))
        sys.exit(1)

    try:
        import cv2
    except ImportError as e:
        print(json.dumps({
            'success': False,
            'error': f'OpenCV not installed: {str(e)}'
        }))
        sys.exit(1)

    try:
        # Load image (OpenCV uses BGR internally)
        img = cv2.imread(input_path, cv2.IMREAD_COLOR)
        if img is None:
            print(json.dumps({
                'success': False,
                'error': 'Cannot decode image. Corrupted or unsupported format.'
            }))
            sys.exit(1)

        height, width = img.shape[:2]

        # Prefer YuNet DNN when available, otherwise use the built-in Haar cascade.
        # Both are bundled with OpenCV, so no external model download is needed.
        faces = None
        model_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'face_detection_yunet_2023mar.onnx')
        if os.path.exists(model_path):
            try:
                detector = cv2.FaceDetectorYN.create(
                    model_path,
                    '',
                    (width, height),
                    score_threshold=0.7,
                    nms_threshold=0.3,
                    top_k=100,
                )
                detector.setInputSize((width, height))
                _, faces = detector.detect(img)
                if faces is None:
                    faces = []
                else:
                    # YuNet boxes: [x, y, w, h, ...] with floats
                    faces = [[int(float(f[0])), int(float(f[1])), int(float(f[2])), int(float(f[3]))] for f in faces]
            except Exception:
                faces = None

        # Fall back to Haar when YuNet is unavailable or found nothing.
        if not faces:
            cascade = cv2.CascadeClassifier(
                cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
            )
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            faces = cascade.detectMultiScale(
                gray,
                scaleFactor=1.1,
                minNeighbors=5,
                minSize=(30, 30),
            )
            faces = [[int(x), int(y), int(w), int(h)] for (x, y, w, h) in faces]

        if not faces:
            print(json.dumps({
                'success': False,
                'error': 'no_face',
                'message': 'Tidak ada wajah terdeteksi pada gambar ini.'
            }))
            sys.exit(1)

        margin = 0.2

        for (x, y, w, h) in faces:
            # Expand face area by ~20% while clamping to image bounds.
            mx = int(w * margin)
            my = int(h * margin)

            x1 = max(0, x - mx)
            y1 = max(0, y - my)
            x2 = min(width, x + w + mx)
            y2 = min(height, y + h + my)

            if x1 >= x2 or y1 >= y2:
                continue

            roi = img[y1:y2, x1:x2]

            if method == 'pixelate':
                # Mosaic effect: shrink the face region then scale it back up.
                roi_h, roi_w = roi.shape[:2]
                block = max(2, strength)  # bigger block => stronger mosaic effect
                small_w = max(1, roi_w // block)
                small_h = max(1, roi_h // block)

                small = cv2.resize(roi, (small_w, small_h), interpolation=cv2.INTER_LINEAR)
                roi = cv2.resize(small, (roi_w, roi_h), interpolation=cv2.INTER_NEAREST)
            else:
                # Gaussian blur. Kernel must be odd.
                kernel = strength * 10 + 1
                if kernel % 2 == 0:
                    kernel += 1
                roi = cv2.GaussianBlur(roi, (kernel, kernel), 0)

            img[y1:y2, x1:x2] = roi

        # Ensure output directory exists.
        output_dir = os.path.dirname(output_path)
        if output_dir:
            os.makedirs(output_dir, exist_ok=True)

        ok = cv2.imwrite(output_path, img)
        if not ok:
            print(json.dumps({
                'success': False,
                'error': 'Failed to write output image'
            }))
            sys.exit(1)

        print(json.dumps({
            'success': True,
            'faces': len(faces),
            'method': method,
        }))

    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': f'Face blur failed: {str(e)}'
        }))
        sys.exit(1)


if __name__ == '__main__':
    main()
