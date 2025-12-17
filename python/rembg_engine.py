#!/usr/bin/env python3
"""
PixelHop - Background Remover Engine (rembg)
Removes background from images

Usage: python3 rembg_engine.py <input_path> <output_path> [model]

Models: u2net (default), u2netp (fast), u2net_human_seg, silueta

Outputs JSON only to stdout. All logs go to stderr.
"""

import sys
import os
import json

# Set home directory for www-data to access model cache
os.environ['HOME'] = '/var/www'
os.environ['U2NET_HOME'] = '/var/www/.u2net'

def main():
    if len(sys.argv) < 3:
        print(json.dumps({
            'success': False,
            'error': 'Usage: python3 rembg_engine.py <input_path> <output_path> [model]'
        }))
        sys.exit(1)
    
    input_path = sys.argv[1]
    output_path = sys.argv[2]
    model_name = sys.argv[3] if len(sys.argv) > 3 else 'u2net'
    
    # Validate input file exists
    if not os.path.exists(input_path):
        print(json.dumps({
            'success': False,
            'error': f'Input file not found: {input_path}'
        }))
        sys.exit(1)
    
    # Validate model name
    valid_models = ['u2net', 'u2netp', 'u2net_human_seg', 'u2net_cloth_seg', 'silueta', 'isnet-general-use']
    if model_name not in valid_models:
        print(json.dumps({
            'success': False,
            'error': f'Invalid model. Valid options: {", ".join(valid_models)}'
        }))
        sys.exit(1)
    
    try:
        from rembg import remove, new_session
        from PIL import Image
        
        # Get input file size
        input_size = os.path.getsize(input_path)
        
        # Load image
        with Image.open(input_path) as img:
            input_width, input_height = img.size
            input_format = img.format or 'UNKNOWN'
            
            # Create session with specified model
            session = new_session(model_name)
            
            # Remove background
            output_img = remove(
                img,
                session=session,
                alpha_matting=False,  # Faster without alpha matting
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
            'width': input_width,
            'height': input_height,
            'input_format': input_format,
            'output_format': 'PNG',
            'model': model_name,
        }
        
        print(json.dumps(output))
        
    except ImportError as e:
        print(json.dumps({
            'success': False,
            'error': f'rembg not installed: {str(e)}'
        }))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({
            'success': False,
            'error': f'Background removal failed: {str(e)}'
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()
