# Third-Party Model Notices

PixelHop uses the following third-party AI models at runtime. **No model
weights are committed to this repository** — they are downloaded
separately via `scripts/download_models.sh` or by the rembg library at
first use.

## rembg (background removal library)

- **Source:** <https://github.com/danielgatis/rembg>
- **License:** MIT
- **License URL:** <https://github.com/danielgatis/rembg/blob/main/LICENSE.txt>
- **Usage:** Server-side background removal via the `rembg` Python
  package. Ships multiple U²-Net model variants (u2net, u2netp,
  u2net_human_seg, silueta, isnet-general-use).
- **Commercial use:** Permitted under the MIT licence — retain the
  copyright notice.

## BiRefNet (high-definition background removal)

- **Source:** <https://github.com/ZhengPeng7/BiRefNet>
- **License:** MIT
- **License URL:** <https://github.com/ZhengPeng7/BiRefNet/blob/main/LICENSE>
- **ONNX weights distributed via:** rembg releases
  (<https://github.com/danielgatis/rembg/releases>)
- **Usage:** Optional HD background-removal models (`birefnet-general`,
  `birefnet-portrait`) loaded through rembg.
- **Commercial use:** Permitted under the MIT licence — retain the
  copyright notice.

## Real-ESRGAN (image upscaling)

- **Source:** <https://github.com/xinntao/Real-ESRGAN>
- **License:** BSD-3-Clause
- **License URL:** <https://github.com/xinntao/Real-ESRGAN/blob/master/LICENSE>
- **ONNX weights source:** Community ONNX conversion of the canonical
  `RealESRGAN_x2plus` weights, hosted on Hugging Face by SceneWorks
  (<https://huggingface.co/SceneWorks/real-esrgan-onnx>). **Upstream
  `xinntao/Real-ESRGAN` publishes no official ONNX artifact** (its
  releases contain only `.pth` weights and ncnn-vulkan packages), so
  this is a third-party conversion rather than an official release.
- **Integrity:** `scripts/download_models.sh` pins the SHA-256 of this
  conversion and verifies it after download. Operators may point
  `REALESRGAN_URL` at their own mirror (and `REALESRGAN_SHA256` at the
  mirror's hash); the pinned checksum is enforced otherwise.
- **Usage:** Server-side 2× image upscaling via ONNX Runtime (CPU).
- **Commercial use:** Permitted under the BSD-3-Clause licence — retain
  the copyright notice and do not use the project name for endorsement
  without permission.

## LaMa (Large Mask Inpainting)

- **Source:** <https://github.com/advimman/lama>
- **License:** Apache-2.0
- **License URL:** <https://github.com/advimman/lama/blob/main/LICENSE>
- **ONNX weights source:** Community ONNX conversion of the canonical
  `big-lama` (LaMa) weights, hosted on Hugging Face by Carve
  (<https://huggingface.co/Carve/LaMa-ONNX>). **Upstream
  `advimman/lama` publishes no official ONNX artifact**, so this is a
  third-party conversion rather than an official release.
- **Integrity:** `scripts/download_models.sh` pins the SHA-256 of this
  conversion and verifies it after download. Operators may point
  `LAMA_URL` at their own mirror (and `LAMA_SHA256` at the mirror's
  hash); the pinned checksum is enforced otherwise.
- **Usage:** Server-side object / watermark removal (magic eraser) via
  ONNX Runtime (CPU).
- **Commercial use:** Permitted under the Apache-2.0 licence — retain
  the copyright notice and NOTICE file (if present); include a copy of
  the licence with any redistribution.

---

## Summary

| Model | Licence | Commercial OK? |
| --- | --- | --- |
| rembg / U²-Net variants | MIT | Yes |
| BiRefNet | MIT | Yes |
| Real-ESRGAN | BSD-3-Clause | Yes |
| LaMa (ONNX) | Apache-2.0 | Yes |

All listed licences permit commercial server-side use. Retain the
original copyright and licence notices as required by each licence.
