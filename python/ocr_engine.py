#!/usr/bin/env python3
"""
PixelHop - OCR Engine (PaddleOCR)
Extracts text from images using PaddleOCR.

Usage: python3 ocr_engine.py <image_path> [language]

Outputs JSON only to stdout. All logs go to stderr.

Model selection:
- Prefers PP-OCRv5 when the installed PaddleOCR supports it, falls back to
  PP-OCRv4, then to the installed default.
- Enables document orientation classification and unwarping when supported.

Preprocessing:
- Images whose longest side is < 1000px are upscaled 1.5x and lightly
  denoised before OCR to improve accuracy on small/skewed text.
"""

import sys
import os
import json
import warnings
import tempfile

# Suppress all warnings and logging
warnings.filterwarnings('ignore')
os.environ['PADDLEX_LOGGING_LEVEL'] = 'ERROR'
os.environ['DISABLE_MODEL_SOURCE_CHECK'] = 'True'

# Set home directory for www-data to access model cache
os.environ['HOME'] = '/var/www'
os.environ['PADDLEX_HOME'] = '/var/www/.paddlex'

PREPROCESS_MAX_SIDE = 1000
UPSCALE_FACTOR = 1.5


def _version_tuple(version):
    """Parse a version string into a tuple of ints, e.g. '3.0.0' -> (3, 0, 0)."""
    parts = []
    for chunk in str(version).split('.'):
        digits = ''
        for char in chunk:
            if char.isdigit():
                digits += char
            else:
                break
        if digits:
            parts.append(int(digits))
        else:
            break
    return tuple(parts)


def _safe_float(value):
    """Convert a possibly-numpy score to float without crashing."""
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


def _safe_remove(path):
    """Remove a temporary file, ignoring any filesystem errors."""
    if not path:
        return
    try:
        if os.path.exists(path):
            os.remove(path)
    except OSError:
        pass


def _bbox_to_list(bbox):
    """Normalize a PaddleOCR bounding box into JSON-serializable lists."""
    if bbox is None:
        return None
    if hasattr(bbox, 'tolist'):
        bbox = bbox.tolist()
    if not isinstance(bbox, list):
        return bbox
    try:
        return [[float(point[0]), float(point[1])] for point in bbox]
    except (TypeError, ValueError, IndexError):
        return bbox


def _is_v3_result(result):
    """Return True when result looks like PaddleOCR 3.x predict() output."""
    if isinstance(result, dict):
        return 'rec_texts' in result
    if isinstance(result, list) and result:
        return isinstance(result[0], dict)
    return False


def _looks_like_legacy_line(item):
    """Return True when item looks like a legacy 2.x OCR line item."""
    if not isinstance(item, (list, tuple)) or len(item) < 2:
        return False
    box = item[0]
    return isinstance(box, (list, tuple)) and len(box) == 4


def _parse_v3_result(result):
    """Parse PaddleOCR 3.x predict() output (list of result dicts)."""
    text_blocks = []
    full_text = []
    confidence_sum = 0.0
    confidence_count = 0

    pages = result if isinstance(result, list) else [result]
    for page in pages:
        if not isinstance(page, dict):
            continue
        rec_texts = page.get('rec_texts')
        rec_scores = page.get('rec_scores')
        dt_polys = page.get('dt_polys')
        if rec_texts is None:
            rec_texts = []
        if rec_scores is None:
            rec_scores = []
        if dt_polys is None:
            dt_polys = []

        for i, text in enumerate(rec_texts):
            score = _safe_float(rec_scores[i]) if i < len(rec_scores) else 0.0
            bbox = _bbox_to_list(dt_polys[i]) if i < len(dt_polys) else None

            text_blocks.append({
                'text': text,
                'confidence': round(score, 4),
                'bbox': bbox,
            })
            full_text.append(text)
            confidence_sum += score
            confidence_count += 1

    return text_blocks, full_text, confidence_sum, confidence_count


def _parse_legacy_result(result):
    """Parse legacy PaddleOCR 2.x ocr() output (nested lists)."""
    text_blocks = []
    full_text = []
    confidence_sum = 0.0
    confidence_count = 0

    # Legacy ocr() may return either a single page of lines or a list of pages.
    if isinstance(result, list) and result and _looks_like_legacy_line(result[0]):
        pages = [result]
    else:
        pages = result if isinstance(result, list) else [result]

    for page in pages:
        if not isinstance(page, list):
            continue
        for line in page:
            if not isinstance(line, (list, tuple)) or len(line) < 2:
                continue
            text_score = line[1]
            text = None
            score = 0.0
            if isinstance(text_score, (list, tuple)) and len(text_score) >= 2:
                text = text_score[0]
                score = _safe_float(text_score[1])
            elif isinstance(text_score, str):
                text = text_score
            else:
                continue
            if text is None:
                continue

            text_blocks.append({
                'text': text,
                'confidence': round(score, 4),
                'bbox': _bbox_to_list(line[0]),
            })
            full_text.append(text)
            confidence_sum += score
            confidence_count += 1

    return text_blocks, full_text, confidence_sum, confidence_count


def _create_paddle_ocr(lang):
    """
    Create a PaddleOCR instance with the most accurate available model.

    Tries PP-OCRv5 first, then PP-OCRv4, then the installed default. Also
    enables document orientation classification and unwarping when supported.
    """
    import paddleocr
    from paddleocr import PaddleOCR

    version = str(getattr(paddleocr, '__version__', '') or 'unknown').strip()
    print('[ocr_engine] paddleocr version: ' + version, file=sys.stderr)

    version_tuple = _version_tuple(version)
    candidates = []
    if not version_tuple or version_tuple >= (3, 0):
        candidates.append('PP-OCRv5')
    if not version_tuple or version_tuple >= (2, 7):
        candidates.append('PP-OCRv4')

    last_error = None
    for ocr_version in candidates + [None]:
        for use_doc_options in (True, False):
            kwargs = {'lang': lang}
            if ocr_version:
                kwargs['ocr_version'] = ocr_version
            if use_doc_options:
                kwargs['use_doc_orientation_classify'] = True
                kwargs['use_doc_unwarping'] = True

            try:
                ocr = PaddleOCR(**kwargs)
                print(
                    '[ocr_engine] initialized ocr_version=%s doc_options=%s'
                    % (ocr_version or 'default', use_doc_options),
                    file=sys.stderr,
                )
                return ocr, ocr_version, use_doc_options
            except Exception as exc:
                last_error = exc
                print(
                    '[ocr_engine] PaddleOCR init failed '
                    '(ocr_version=%s doc_options=%s): %s'
                    % (ocr_version or 'default', use_doc_options, exc),
                    file=sys.stderr,
                )

    if last_error is not None:
        raise last_error
    raise RuntimeError('Unable to initialize PaddleOCR')


def _denoise_image(image):
    """
    Light denoising with OpenCV when available.

    Returns a denoised PIL Image, or None when OpenCV is unavailable or the
    operation fails (caller then falls back to PIL contrast/sharpness).
    """
    try:
        import cv2
        import numpy as np

        array = np.asarray(image)
        if array.ndim == 2:
            array = cv2.cvtColor(array, cv2.COLOR_GRAY2BGR)
        elif array.shape[2] == 4:
            array = cv2.cvtColor(array, cv2.COLOR_RGBA2BGR)
        else:
            array = cv2.cvtColor(array, cv2.COLOR_RGB2BGR)

        denoised = cv2.fastNlMeansDenoisingColored(array, None, 3, 3, 7, 21)
        denoised = cv2.cvtColor(denoised, cv2.COLOR_BGR2RGB)

        from PIL import Image
        return Image.fromarray(denoised)
    except Exception:
        return None


def _preprocess_for_ocr(image_path):
    """
    Upscale + light denoise for small images (longest side < 1000px).

    Returns (ocr_path, temp_path). When temp_path is not None a temporary
    preprocessed image was created and must be deleted after OCR.
    """
    try:
        from PIL import Image
    except Exception:
        return image_path, None

    try:
        image = Image.open(image_path)
        image.load()
    except Exception:
        return image_path, None

    try:
        # Only preprocess small images; leave larger ones untouched.
        if max(image.size) >= PREPROCESS_MAX_SIDE:
            return image_path, None

        resampling = getattr(Image, 'Resampling', None)
        lanczos = getattr(resampling, 'LANCZOS', None) if resampling is not None else None
        if lanczos is None:
            lanczos = getattr(Image, 'LANCZOS', None)
        if lanczos is None:
            lanczos = getattr(Image, 'BICUBIC', 3)

        new_size = (
            max(1, int(image.width * UPSCALE_FACTOR)),
            max(1, int(image.height * UPSCALE_FACTOR)),
        )
        image = image.resize(new_size, lanczos)

        denoised = _denoise_image(image)
        if denoised is not None:
            image = denoised
        else:
            from PIL import ImageEnhance
            image = ImageEnhance.Contrast(image).enhance(1.1)
            image = ImageEnhance.Sharpness(image).enhance(1.2)

        if image.mode not in ('RGB', 'L', 'RGBA'):
            image = image.convert('RGB')

        fd, temp_path = tempfile.mkstemp(prefix='pixelhop_ocr_', suffix='.png')
        os.close(fd)
        try:
            image.save(temp_path, format='PNG')
        except Exception:
            _safe_remove(temp_path)
            return image_path, None

        print('[ocr_engine] preprocessing: upscaled + denoised small image', file=sys.stderr)
        return temp_path, temp_path
    except Exception:
        return image_path, None


def main():
    if len(sys.argv) < 2:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python3 ocr_engine.py <image_path> [language]'
        }))
        sys.exit(1)

    image_path = sys.argv[1]
    language = sys.argv[2] if len(sys.argv) > 2 else 'en'

    # Validate file exists
    if not os.path.exists(image_path):
        print(json.dumps({
            'success': False,
            'error': f'Image not found: {image_path}'
        }))
        sys.exit(1)

    try:
        # Map common language codes
        lang_map = {
            'eng': 'en',
            'en': 'en',
            'chi_sim': 'ch',
            'chi_tra': 'chinese_cht',
            'jpn': 'japan',
            'ja': 'japan',
            'kor': 'korean',
            'ko': 'korean',
            'fra': 'fr',
            'fr': 'fr',
            'deu': 'german',
            'de': 'german',
            'spa': 'es',
            'es': 'es',
            'por': 'pt',
            'pt': 'pt',
            'ita': 'it',
            'it': 'it',
            'rus': 'ru',
            'ru': 'ru',
            'ara': 'ar',
            'ar': 'ar',
            'tha': 'th',
            'th': 'th',
            'vie': 'vi',
            'vi': 'vi',
            'ind': 'id',
            'id': 'id',
        }

        paddle_lang = lang_map.get(language.lower(), 'en')

        # Select the best available PaddleOCR model (PP-OCRv5 -> PP-OCRv4 ->
        # installed default) with guarded accuracy options.
        ocr, ocr_version, use_doc_options = _create_paddle_ocr(paddle_lang)

        # Preprocess small images for better accuracy, then OCR from the
        # (possibly temporary) preprocessed file.
        ocr_path, temp_path = _preprocess_for_ocr(image_path)
        try:
            if hasattr(ocr, 'predict'):
                result = ocr.predict(ocr_path)
                if _is_v3_result(result):
                    text_blocks, full_text, confidence_sum, confidence_count = _parse_v3_result(result)
                else:
                    text_blocks, full_text, confidence_sum, confidence_count = _parse_legacy_result(result)
            else:
                try:
                    result = ocr.ocr(ocr_path, cls=True)
                except TypeError:
                    result = ocr.ocr(ocr_path)
                text_blocks, full_text, confidence_sum, confidence_count = _parse_legacy_result(result)
        finally:
            _safe_remove(temp_path)

        avg_confidence = confidence_sum / confidence_count if confidence_count > 0 else 0

        output = {
            'success': True,
            'text': '\n'.join(full_text),
            'blocks': text_blocks,
            'block_count': len(text_blocks),
            'language': paddle_lang,
            'average_confidence': round(avg_confidence, 4),
        }

        print(json.dumps(output, ensure_ascii=False))

    except ImportError as e:
        print(json.dumps({
            'success': False,
            'error': f'PaddleOCR not installed: {str(e)}'
        }))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': f'OCR failed: {str(e)}'
        }))
        sys.exit(1)


if __name__ == '__main__':
    main()
