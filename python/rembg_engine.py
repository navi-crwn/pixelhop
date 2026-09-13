#!/usr/bin/env python3
"""
PixelHop - Background Remover Engine (rembg)
Removes background from images

Usage: python3 rembg_engine.py <input_path> <output_path> [model] [--alpha-matting]

Models: u2net (default), u2netp (fast), u2net_human_seg, silueta,
        isnet-general-use, birefnet-general (HD), birefnet-portrait (HD)

Outputs JSON only to stdout. All logs go to stderr.
"""

import sys
import os
import json

# Set home directory for www-data to access model cache.
# Use setdefault so operator-provided overrides are honoured.
os.environ.setdefault('HOME', '/var/www')
os.environ.setdefault('U2NET_HOME', os.path.join(os.environ['HOME'], '.u2net'))

VALID_MODELS = [
    'u2net',
    'u2netp',
    'u2net_human_seg',
    'silueta',
    'isnet-general-use',
    'birefnet-general',
    'birefnet-portrait',
]

BIREFNET_MODELS = ['birefnet-general', 'birefnet-portrait']
BIREFNET_MAX_DIM = 1500


def emit_error(message, **extra):
    """Print a JSON error payload and exit."""
    payload = {'success': False, 'error': message}
    payload.update(extra)
    print(json.dumps(payload))
    sys.exit(1)


def birefnet_model_file_exists(model_name):
    """Check whether a BiRefNet model file has already been downloaded.

    rembg looks in both the per-model directory and the legacy flat directory
    (see rembg.sessions.base.BaseSession.resolve_existing).
    """
    home = os.environ.get('U2NET_HOME', '/var/www/.u2net')
    candidates = [
        os.path.join(home, 'models', model_name, model_name + '.onnx'),
        os.path.join(home, model_name + '.onnx'),
    ]
    return any(os.path.exists(path) for path in candidates)


def main():
    if len(sys.argv) < 3:
        emit_error(
            'Usage: python3 rembg_engine.py <input_path> <output_path> '
            '[model] [--alpha-matting]'
        )

    input_path = sys.argv[1]
    output_path = sys.argv[2]

    model_name = 'u2net'
    alpha_matting = False

    for arg in sys.argv[3:]:
        if arg == '--alpha-matting':
            alpha_matting = True
        elif arg.startswith('-'):
            emit_error(f'Unknown argument: {arg}')
        else:
            model_name = arg

    # Validate model name against the supported whitelist
    if model_name not in VALID_MODELS:
        emit_error(
            f'Invalid model "{model_name}". Valid options: {", ".join(VALID_MODELS)}'
        )

    # Validate input file exists
    if not os.path.exists(input_path):
        emit_error(f'Input file not found: {input_path}')

    # BiRefNet is opt-in and downloaded by a separate script. Fail clearly
    # when the model has not been downloaded yet instead of falling back.
    if model_name in BIREFNET_MODELS and not birefnet_model_file_exists(model_name):
        emit_error(
            f'Model "{model_name}" is not downloaded yet. model_not_found: '
            'run the BiRefNet model download script first.',
            error_code='model_not_found',
        )

    try:
        from rembg import remove, new_session
        from PIL import Image

        # Get input file size
        input_size = os.path.getsize(input_path)

        # Load image
        with Image.open(input_path) as img:
            original_width, original_height = img.size
            input_format = img.format or 'UNKNOWN'

            processed_width = original_width
            processed_height = original_height

            # Pre-resize BiRefNet inputs to keep CPU/RAM bounded.
            # BiRefNet normalizes internally to 1024x1024, so resizing the
            # long edge down to 1500px preserves quality while avoiding very
            # expensive CPU inference on huge images.
            if model_name in BIREFNET_MODELS and max(original_width, original_height) > BIREFNET_MAX_DIM:
                ratio = BIREFNET_MAX_DIM / max(original_width, original_height)
                processed_width = max(1, round(original_width * ratio))
                processed_height = max(1, round(original_height * ratio))
                img_for_removal = img.resize(
                    (processed_width, processed_height),
                    Image.Resampling.LANCZOS,
                )
            else:
                img_for_removal = img

            # Create session with specified model
            session = new_session(model_name)

            # Remove background
            output_img = remove(
                img_for_removal,
                session=session,
                alpha_matting=alpha_matting,
                only_mask=False,
            )

            # Ensure output is PNG (for transparency)
            if not output_path.lower().endswith('.png'):
                output_path = os.path.splitext(output_path)[0] + '.png'

            # Ensure output directory exists
            os.makedirs(os.path.dirname(output_path), exist_ok=True)

            # Save with transparency
            output_img.save(output_path, 'PNG', optimize=True)

        # Get output file size
        output_size = os.path.getsize(output_path)

        output = {
            'success': True,
            'input_path': input_path,
            'output_path': output_path,
            'input_size': input_size,
            'output_size': output_size,
            'width': original_width,
            'height': original_height,
            'input_format': input_format,
            'output_format': 'PNG',
            'model': model_name,
            'alpha_matting': alpha_matting,
        }

        if processed_width != original_width or processed_height != original_height:
            output['processed_width'] = processed_width
            output['processed_height'] = processed_height

        print(json.dumps(output))

    except ImportError as e:
        emit_error(f'rembg not installed: {str(e)}')
    except Exception as e:
        emit_error(f'Background removal failed: {str(e)}')


if __name__ == '__main__':
    main()
