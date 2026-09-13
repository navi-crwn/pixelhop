#!/usr/bin/env python3
"""
PixelHop - Magic Eraser Engine (LaMa via ONNX Runtime)

Natural object / watermark removal through inpainting. The user paints a
mask over the area to erase and the LaMa model fills that region with
surrounding context. This engine deliberately avoids PyTorch and only uses
onnxruntime (CPU) so it runs on lightweight hosts.

Usage:
    python3 erase_engine.py <input_path> <mask_path> <output_path>

    input_path   Original image (JPEG/PNG/WebP, RGB or RGBA).
    mask_path    Mask image (PNG recommended). White pixels are erased;
                 black pixels are kept. The mask is resized to match the
                 input before inference.
    output_path  Where the inpainted PNG result is written.

Model resolution:
    1. LAMA_MODEL environment variable (override)
    2. lama.onnx next to this script
    3. ~/.cache/lama/lama.onnx

Fixed-shape model handling:
    The selected LaMa ONNX model may have a fixed input shape (e.g.
    512x512).  At runtime this engine inspects the ONNX session inputs.
    When the height and width dimensions are static integers the image
    and mask are letterboxed (padded with edge-reflection for the image,
    zero for the mask) to the model's expected square, inference runs at
    that resolution, and the output is cropped back and resized to the
    original dimensions.  When the model declares dynamic axes the
    previous behaviour (resize longest edge to MAX_SIZE) is retained.

Outputs JSON only to stdout. All logs go to stderr.
"""

import json
import os
import sys
import time

# Home may not be set when called from PHP/Apache.
os.environ.setdefault('HOME', '/var/www')

MAX_SIZE = 1024


def emit(result: dict) -> None:
    """Print the final JSON result to stdout."""
    print(json.dumps(result))


# ---------------------------------------------------------------------------
# Letterbox helpers – used when the ONNX model has a fixed input shape.
# ---------------------------------------------------------------------------

def calculate_letterbox(src_w, src_h, target_w, target_h):
    """Return (scale, pad_top, pad_left, new_w, new_h) for letterboxing.

    The image is scaled so it fits entirely within *target_w* x *target_h*
    without distortion, then centred with padding on the remaining edges.
    """
    scale = min(target_w / src_w, target_h / src_h)
    new_w = int(round(src_w * scale))
    new_h = int(round(src_h * scale))
    pad_left = (target_w - new_w) // 2
    pad_top = (target_h - new_h) // 2
    return scale, pad_top, pad_left, new_w, new_h


def letterbox_image(image_np, target_h, target_w, pad_top, pad_left, new_h, new_w):
    """Resize *image_np* (H,W,3 float32 0-1) into a letterboxed canvas.

    Padding uses edge-reflection so the inpainting network sees plausible
    context rather than black bars.
    """
    import numpy as np
    from PIL import Image

    # Resize to the inner rectangle first.
    pil = Image.fromarray(
        (np.clip(image_np, 0.0, 1.0) * 255.0).astype('uint8'), mode='RGB'
    )
    pil = pil.resize((new_w, new_h), Image.LANCZOS)
    resized = np.asarray(pil, dtype=np.float32) / 255.0

    # Build the padded canvas with edge-reflection.
    canvas = np.zeros((target_h, target_w, 3), dtype=np.float32)
    canvas[pad_top:pad_top + new_h, pad_left:pad_left + new_w, :] = resized

    # Reflect edges into padding regions.
    # Top
    if pad_top > 0:
        for y in range(pad_top):
            src_y = min(pad_top - y, new_h - 1)
            canvas[y, pad_left:pad_left + new_w, :] = resized[src_y, :, :]
    # Bottom
    bottom_start = pad_top + new_h
    pad_bottom = target_h - bottom_start
    if pad_bottom > 0:
        for y in range(pad_bottom):
            src_y = max(new_h - 1 - y, 0)
            canvas[bottom_start + y, pad_left:pad_left + new_w, :] = resized[src_y, :, :]
    # Left
    if pad_left > 0:
        for x in range(pad_left):
            src_x = min(pad_left - x, new_w - 1)
            canvas[:, x, :] = canvas[:, pad_left + src_x, :]
    # Right
    right_start = pad_left + new_w
    pad_right = target_w - right_start
    if pad_right > 0:
        for x in range(pad_right):
            src_x = max(new_w - 1 - x, 0)
            canvas[:, right_start + x, :] = canvas[:, pad_left + src_x, :]

    return canvas


def letterbox_mask(mask_np, target_h, target_w, pad_top, pad_left, new_h, new_w):
    """Resize *mask_np* (H,W float32 0-1) into a letterboxed canvas.

    Padding is zero (unmasked) so the model does not try to inpaint the
    letterbox bars.
    """
    import numpy as np
    from PIL import Image

    pil = Image.fromarray(
        (np.clip(mask_np, 0.0, 1.0) * 255.0).astype('uint8'), mode='L'
    )
    pil = pil.resize((new_w, new_h), Image.BILINEAR)
    resized = np.asarray(pil, dtype=np.float32) / 255.0

    canvas = np.zeros((target_h, target_w), dtype=np.float32)
    canvas[pad_top:pad_top + new_h, pad_left:pad_left + new_w] = resized
    return canvas


def crop_letterbox(output_np, pad_top, pad_left, new_h, new_w):
    """Crop the letterbox padding from the model output (H,W,3)."""
    return output_np[pad_top:pad_top + new_h, pad_left:pad_left + new_w, :]


# ---------------------------------------------------------------------------
# Model input introspection
# ---------------------------------------------------------------------------

def get_model_fixed_hw(session):
    """Inspect the ONNX session and return (H, W) if both are static ints.

    Checks the *image* input (the one with 3 channels).  Returns ``None``
    when either dimension is dynamic (a string like ``'height'``).
    """
    for inp in session.get_inputs():
        shape = inp.shape  # e.g. [1, 3, 512, 512] or [1, 3, 'height', 'width']
        if len(shape) == 4 and shape[1] == 3:
            h, w = shape[2], shape[3]
            if isinstance(h, int) and isinstance(w, int):
                return (h, w)
            return None
    return None


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    started = time.time()

    if len(sys.argv) < 4:
        emit({
            'success': False,
            'error': 'Usage: python3 erase_engine.py <input_path> <mask_path> <output_path>',
        })
        sys.exit(1)

    input_path = sys.argv[1]
    mask_path = sys.argv[2]
    output_path = sys.argv[3]

    try:
        if not os.path.exists(input_path):
            emit({'success': False, 'error': f'Input file not found: {input_path}'})
            sys.exit(1)

        if not os.path.exists(mask_path):
            emit({'success': False, 'error': f'Mask file not found: {mask_path}'})
            sys.exit(1)

        model_path = resolve_model_path()
        if model_path is None:
            emit({'success': False, 'error': 'model_not_found'})
            sys.exit(1)

        import numpy as np
        import onnxruntime as ort
        from PIL import Image, ImageFilter

        original = Image.open(input_path)
        original.load()

        original_width, original_height = original.size
        input_format = original.format or 'UNKNOWN'

        # Convert the source to RGB before processing. Preserve RGBA so we can
        # re-attach the original alpha channel to the output (LaMa works in RGB).
        has_alpha = original.mode in ('RGBA', 'LA') or (
            original.mode == 'P' and 'transparency' in original.info
        )
        rgba = original.convert('RGBA')
        alpha_channel = rgba.getchannel('A') if has_alpha else None
        rgb = rgba.convert('RGB')

        # -- Create ONNX session first so we can inspect the model shape. --
        providers = ['CPUExecutionProvider']
        sess_options = ort.SessionOptions()
        sess_options.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
        sess_options.intra_op_num_threads = max(1, (os.cpu_count() or 2) - 1)
        sess_options.inter_op_num_threads = 1

        session = ort.InferenceSession(
            model_path,
            sess_options=sess_options,
            providers=providers,
        )

        fixed_hw = get_model_fixed_hw(session)
        use_letterbox = fixed_hw is not None

        if use_letterbox:
            # -----------------------------------------------------------
            # Fixed-shape path: letterbox to model dimensions.
            # -----------------------------------------------------------
            model_h, model_w = fixed_hw

            # Resize the long edge to MAX_SIZE first to cap memory, but
            # only if the image is actually larger.
            rgb_work, _ = resize_to_max(rgb, MAX_SIZE)
            work_width, work_height = rgb_work.size

            # Load and normalize the mask to the working size.
            mask = load_mask(mask_path, work_width, work_height)

            # Slight dilation blends the inpainted edge.
            mask_img = Image.fromarray(mask, mode='L').filter(
                ImageFilter.MaxFilter(5)
            )
            mask = np.asarray(mask_img, dtype=np.float32) / 255.0

            image_np = np.asarray(rgb_work, dtype=np.float32) / 255.0

            # Letterbox image and mask into the model's fixed canvas.
            lb_scale, pad_top, pad_left, inner_w, inner_h = calculate_letterbox(
                work_width, work_height, model_w, model_h,
            )
            image_lb = letterbox_image(
                image_np, model_h, model_w, pad_top, pad_left, inner_h, inner_w,
            )
            mask_lb = letterbox_mask(
                mask, model_h, model_w, pad_top, pad_left, inner_h, inner_w,
            )

            # Build NCHW tensors at the model's expected resolution.
            image_tensor = np.transpose(image_lb, (2, 0, 1))[np.newaxis, ...].astype(np.float32)
            mask_tensor = mask_lb[np.newaxis, np.newaxis, ...].astype(np.float32)
        else:
            # -----------------------------------------------------------
            # Dynamic-shape path: resize longest edge, pass as-is.
            # -----------------------------------------------------------
            rgb_work, _ = resize_to_max(rgb, MAX_SIZE)
            work_width, work_height = rgb_work.size

            mask = load_mask(mask_path, work_width, work_height)

            mask_img = Image.fromarray(mask, mode='L').filter(
                ImageFilter.MaxFilter(5)
            )
            mask = np.asarray(mask_img, dtype=np.float32) / 255.0

            image_np = np.asarray(rgb_work, dtype=np.float32) / 255.0

            image_tensor = np.transpose(image_np, (2, 0, 1))[np.newaxis, ...]
            mask_tensor = mask[np.newaxis, np.newaxis, ...]

            # Placeholders so the post-processing block can be uniform.
            pad_top = pad_left = inner_w = inner_h = 0

        run_options = ort.RunOptions()
        run_options.log_severity_level = 3  # ERROR only

        feeds = build_feeds(session, image_tensor, mask_tensor)
        result = session.run(None, feeds, run_options)
        output_tensor = select_output(session, result, image_tensor)

        # Recover the inpainted RGB image: [1, 3, H, W] -> [H, W, 3].
        if output_tensor.ndim == 4:
            output_tensor = output_tensor[0]
        if output_tensor.shape[0] == 3:
            output_tensor = np.transpose(output_tensor, (1, 2, 0))
        elif output_tensor.shape[-1] == 3:
            pass
        else:
            raise RuntimeError(
                'Unexpected model output shape: ' + str(output_tensor.shape)
            )

        if use_letterbox:
            # Crop the letterbox padding, then resize to the working dims.
            output_tensor = crop_letterbox(output_tensor, pad_top, pad_left, inner_h, inner_w)

        # Ensure output matches the working dimensions before final upscale.
        out_h, out_w = output_tensor.shape[0], output_tensor.shape[1]
        if out_h != work_height or out_w != work_width:
            pil_output = Image.fromarray(
                np.clip(output_tensor * 255.0, 0, 255).astype('uint8'),
                mode='RGB',
            )
            pil_output = pil_output.resize((work_width, work_height), Image.LANCZOS)
            output_tensor = np.asarray(pil_output, dtype=np.float32) / 255.0

        output_np = np.clip(output_tensor * 255.0, 0, 255).astype('uint8')

        # Resize back to the original dimensions.
        final_rgb = Image.fromarray(output_np, mode='RGB')
        if final_rgb.size != (original_width, original_height):
            final_rgb = final_rgb.resize(
                (original_width, original_height), Image.LANCZOS
            )

        if alpha_channel is not None:
            final_rgba = final_rgb.convert('RGBA')
            final_rgba.putalpha(alpha_channel)
            final_rgba.save(output_path, 'PNG', optimize=True)
        else:
            final_rgb.save(output_path, 'PNG', optimize=True)

        duration_ms = int((time.time() - started) * 1000)
        emit({
            'success': True,
            'input_path': input_path,
            'output_path': output_path,
            'model_path': model_path,
            'input_size': os.path.getsize(input_path),
            'output_size': os.path.getsize(output_path),
            'width': original_width,
            'height': original_height,
            'work_width': work_width,
            'work_height': work_height,
            'model_fixed_shape': list(fixed_hw) if fixed_hw else None,
            'input_format': input_format,
            'output_format': 'PNG',
            'model': 'lama',
            'duration_ms': duration_ms,
        })
    except ImportError as exc:
        emit({'success': False, 'error': 'onnxruntime not installed: ' + str(exc)})
        sys.exit(1)
    except Exception as exc:
        emit({'success': False, 'error': str(exc)})
        sys.exit(1)


def resolve_model_path():
    """Return the LaMa ONNX model path or None when no model is available."""
    candidates = []

    env_model = os.environ.get('LAMA_MODEL', '').strip()
    if env_model:
        candidates.append(env_model)

    script_dir = os.path.dirname(os.path.abspath(__file__))
    candidates.append(os.path.join(script_dir, 'lama.onnx'))

    cache_home = os.environ.get(
        'XDG_CACHE_HOME',
        os.path.join(os.path.expanduser('~'), '.cache'),
    )
    candidates.append(os.path.join(cache_home, 'lama', 'lama.onnx'))

    for candidate in candidates:
        if os.path.isfile(candidate):
            return candidate

    return None


def resize_to_max(image, max_size):
    """Resize an image so its longest edge is <= max_size."""
    from PIL import Image as _Img   # noqa: F811 – local re-import for standalone use
    width, height = image.size
    longest = max(width, height)
    if longest <= max_size:
        return image.copy(), 1.0

    scale = max_size / float(longest)
    new_width = max(1, int(round(width * scale)))
    new_height = max(1, int(round(height * scale)))
    return image.resize((new_width, new_height), _Img.LANCZOS), scale


def load_mask(mask_path, target_width, target_height):
    """Load a mask, make white pixels 255, and resize it to the target size."""
    import numpy as np
    from PIL import Image

    with Image.open(mask_path) as mask_img:
        mask_img.load()
        if mask_img.mode == 'RGBA':
            mask_img = mask_img.convert('RGBA')
            mask_img = mask_img.getchannel('A')
        else:
            mask_img = mask_img.convert('L')

        if mask_img.size != (target_width, target_height):
            mask_img = mask_img.resize(
                (target_width, target_height), Image.BILINEAR
            )

        mask = np.asarray(mask_img, dtype=np.float32)
        # Treat any non-black painted pixel as part of the erased area.
        mask = (mask > 10.0).astype(np.float32) * 255.0
        return mask


def build_feeds(session, image_tensor, mask_tensor):
    """Map image/mask tensors onto the model input names."""
    inputs = session.get_inputs()
    feeds = {}

    # The most common LaMa ONNX export uses names like:
    #   image: [1, 3, H, W], mask: [1, 1, H, W]
    # Accept any pair by index fallback so other exports keep working.
    if len(inputs) >= 2:
        feeds[inputs[0].name] = image_tensor
        feeds[inputs[1].name] = mask_tensor
        return feeds

    raise RuntimeError('Expected at least 2 model inputs, got ' + str(len(inputs)))


def select_output(session, result, image_tensor):
    """Pick the most useful output tensor from the ONNX graph."""
    if len(result) == 1:
        return result[0]

    outputs = session.get_outputs()
    for index, candidate in enumerate(result):
        if not hasattr(candidate, 'ndim') or candidate.ndim < 3:
            continue
        try:
            if candidate.shape[0] in (1, image_tensor.shape[0]) and candidate.shape[1] == 3:
                return candidate
        except (IndexError, TypeError):
            continue

    # Last resort: prefer the named output whose name contains "out".
    for output_meta, candidate in zip(outputs, result):
        if 'out' in (output_meta.name or '').lower():
            return candidate

    return result[0]


if __name__ == '__main__':
    main()
