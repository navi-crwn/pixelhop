#!/usr/bin/env python3
"""
PixelHop - OCR Engine (PaddleOCR v3.x)
Extracts text from images using PaddleOCR

Usage: python3 ocr_engine.py <image_path> [language]

Outputs JSON only to stdout. All logs go to stderr.
"""

import sys
import os
import json
import warnings

# Suppress all warnings and logging
warnings.filterwarnings('ignore')
os.environ['PADDLEX_LOGGING_LEVEL'] = 'ERROR'
os.environ['DISABLE_MODEL_SOURCE_CHECK'] = 'True'

# Set home directory for www-data to access model cache
os.environ['HOME'] = '/var/www'
os.environ['PADDLEX_HOME'] = '/var/www/.paddlex'

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
        # Import here to catch import errors
        from paddleocr import PaddleOCR
        
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
        
        # Initialize PaddleOCR v3.x (simplified API)
        ocr = PaddleOCR(lang=paddle_lang)
        
        # Perform OCR - returns dict with 'rec_texts', 'rec_scores', 'dt_polys'
        result = ocr.predict(image_path)
        
        # Parse results (v3.x format)
        text_blocks = []
        full_text = []
        confidence_sum = 0
        confidence_count = 0
        
        if result:
            # v3.x returns a list of result dicts
            for page_result in result:
                rec_texts = page_result.get('rec_texts', [])
                rec_scores = page_result.get('rec_scores', [])
                dt_polys = page_result.get('dt_polys', [])
                
                for i, text in enumerate(rec_texts):
                    score = rec_scores[i] if i < len(rec_scores) else 0.0
                    bbox = dt_polys[i].tolist() if i < len(dt_polys) else None
                    
                    text_blocks.append({
                        'text': text,
                        'confidence': round(float(score), 4),
                        'bbox': bbox
                    })
                    
                    full_text.append(text)
                    confidence_sum += float(score)
                    confidence_count += 1
        
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
