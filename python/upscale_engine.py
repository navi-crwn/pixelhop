#!/usr/bin/env python3
"""
PixelHop - AI HD Upscale Engine (Real-ESRGAN via ONNX Runtime)

Usage: python3 upscale_engine.py <input_path> <output_path> [scale]

Scale: 2 (default) or 4. The x2 ONNX model is used for AI upscaling.
When scale=4 is requested, the AI 2x result is additionally resized with a
high-quality Lanczos filter to reach the final 4x size. Input images are
capped at 1024px on the longest side BEFORE inference to keep CPU/RAM usage
predictable (safe for a 7.8GB RAM server without a GPU).

Outputs JSON only to stdout. All progress logs go to stderr.
"""

import sys
import os
import json


def log(msg: str) -> None:
    """Progress output for the parent process (stderr keeps stdout JSON-clean)."""
    print(msg, file=sys.stderr, flush=True)


def resolve_model_path() -> str:
    """Return the Real-ESRGAN ONNX model path.

    The path can be overridden with the REALESRGAN_MODEL environment variable.
    The default mirrors where the downloader script will place the model.
    """
    env_path = os.environ.get('REALESRGAN_MODEL', '').strip()
    if env_path:
        return os.path.expanduser(env_path)

    # www-data needs a writable home for the model cache.
    os.environ.setdefault('HOME', '/var/www')
    return os.path.expanduser('~/.cache/realesrgan/RealESRGAN_x2plus.onnx')


def parse_scale(raw_scale) -> int:
    try:
        scale = int(raw_scale)
    except (TypeError, ValueError):
        scale = 2

    return scale if scale in (2, 4) else 2


def resize_longest_side(img, max_px: int = 1024):
    """Resize an image so its longest side does not exceed max_px."""
    from PIL import Image

    width, height = img.size
    longest = max(width, height)

    if longest <= max_px:
        return img.copy()

    ratio = max_px / float(longest)
    new_width = max(2, int(round(width * ratio)))
    new_height = max(2, int(round(height * ratio)))

    # Real-ESRGAN is a 2x model; keeping dimensions even avoids ONNX shape
    # surprises with some exported models.
    new_width -= new_width % 2
    new_height -= new_height % 2
    new_width = max(2, new_width)
    new_height = max(2, new_height)

    log(f'Resizing input {width}x{height} -> {new_width}x{new_height} (max {max_px}px)')
    return img.resize((new_width, new_height), Image.LANCZOS)


def upscale_with_onnx(session, img, scale: int):
    """Run Real-ESRGAN inference and return the upscaled PIL image."""
    import numpy as np
    from PIL import Image

    rgb = img.convert('RGB')
    arr = np.asarray(rgb, dtype=np.float32) / 255.0
    arr = arr.transpose(2, 0, 1)
    arr = arr[np.newaxis, :, :, :]

    input_name = session.get_inputs()[0].name
    output_name = session.get_outputs()[0].name

    log('Enhancing with Real-ESRGAN...')
    output = session.run([output_name], {input_name: arr})[0]

    output = np.clip(output[0], 0.0, 1.0)
    output = output.transpose(1, 2, 0)
    output = (output * 255.0).round().astype(np.uint8)

    result = Image.fromarray(output, 'RGB')

    if scale == 4:
        # The x2 model has finished; interpolate the remaining 2x with Lanczos.
        from PIL import Image
        log('Finishing 4x output...')
        w, h = result.size
        result = result.resize((w * 2, h * 2), Image.LANCZOS)

    return result


def main():
    if len(sys.argv) < 3:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python3 upscale_engine.py <input_path> <output_path> [scale]'
        }))
        sys.exit(1)

    input_path = sys.argv[1]
    output_path = sys.argv[2]
    scale = parse_scale(sys.argv[3] if len(sys.argv) > 3 else 2)

    if not os.path.exists(input_path):
        print(json.dumps({
            'success': False,
            'error': f'Input file not found: {input_path}'
        }))
        sys.exit(1)

    model_path = resolve_model_path()

    if not os.path.exists(model_path):
        print(json.dumps({
            'success': False,
            'error': 'model_not_found',
            'path': model_path,
        }))
        sys.exit(1)

    try:
        log('Analyzing...')
        import onnxruntime as ort
        from PIL import Image

        # Bound the model thread pool so a CPU-only box can serve concurrent
        # requests without exhausting all cores at once.
        session_options = ort.SessionOptions()
        session_options.intra_op_num_threads = 2
        session_options.inter_op_num_threads = 1

        log(f'Loading model: {model_path}')
        session = ort.InferenceSession(model_path, sess_options=session_options)

        input_size = os.path.getsize(input_path)

        with Image.open(input_path) as img:
            input_width, input_height = img.size
            input_format = img.format or 'UNKNOWN'

            # RAM/time guard: never run the model on more than 1024px longest side.
            working_img = resize_longest_side(img, 1024)
            processed_width, processed_height = working_img.size

            output_img = upscale_with_onnx(session, working_img, scale)
            output_width, output_height = output_img.size

        # Force PNG output (matches the temp result MIME used by the API).
        if not output_path.lower().endswith('.png'):
            output_path = os.path.splitext(output_path)[0] + '.png'

        os.makedirs(os.path.dirname(output_path), exist_ok=True)

        log('Saving output...')
        output_img.save(output_path, 'PNG', optimize=True)

        output_size = os.path.getsize(output_path)

        print(json.dumps({
            'success': True,
            'input_path': input_path,
            'output_path': output_path,
            'input_size': input_size,
            'output_size': output_size,
            'width': input_width,
            'height': input_height,
            'processed_width': processed_width,
            'processed_height': processed_height,
            'output_width': output_width,
            'output_height': output_height,
            'input_format': input_format,
            'output_format': 'PNG',
            'scale': scale,
            'model': os.path.basename(model_path),
        }))

    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': str(e)
        }))
        sys.exit(1)


if __name__ == '__main__':
    main()
