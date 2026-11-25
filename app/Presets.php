<?php
declare(strict_types=1);
namespace Studio;

/**
 * The ready-made image instructions of the edit panel (Fotorrealista, Melhorar, Ampliar, Expandir, the brush), for
 * requests made on the server: the story writer and MCP use them. The browser has its own copy in assets/app.js, where they are explained;
 * tests/story.mjs checks that the two say exactly the same.
 */
final class Presets
{
    public const UPSCALE = <<<'TEXT'
Recreate this same image at a higher resolution. Preserve exactly the composition, framing, proportions, colors, lighting, faces, text and every element in the same positions. Make the image clean and well defined, with smooth transitions, and remove noise, grain, specks, jagged edges and compression artifacts, without inventing new texture. Do not add, remove or reinterpret anything.
TEXT;

    public const PHOTOREAL = <<<'TEXT'
Turn this image into an ultra-realistic photograph: photograph this exact scene again with a real camera, with real people in a real place. The result is a straight-out-of-camera photo: a single unedited frame, exactly as the camera recorded it, with no filters, retouching or post-processing. Nobody should be able to tell it apart from a real photograph.

Use the attached image as the description of the scene (who and what is in it, where everything is, every shape, the colors and where the light comes from), not as a surface to copy. Its shading style, its highlights and the finish of its skin and materials come from a painter or a renderer: do not trace them, and remove every flaw that is not part of the scene. The forms that the shading describes (every muscle, fold, vein and contour) stay exactly as they are; only the way they are lit and finished becomes photographic.

Preserve exactly, without exceptions:
- Composition, framing, crop, camera angle and perspective.
- Every person's identity: face, facial features and their proportions, expression, gaze, age, skin tone, hair (style, length and color), body shape, build, proportions, pose and gestures.
- Anatomy, exactly as in the original: body and limb proportions, the length and thickness of every limb, hands and fingers, the musculature (the size, shape, position and definition of every muscle), bone structure, body fat, curves and silhouette. Do not slim, bulk up, idealize, simplify or correct the anatomy; render the same body with real skin over it.
- Clothing, accessories, objects, background, setting, and the position and size of every element.
- The color palette, the time of day, and the direction, color and mood of the light.
- Every element of the scene, however small. Do not add, remove, move or redesign anything (other than the flaws listed below), and do not add text, logos or watermarks.
- Where an object or material is stylized beyond what is physically possible, render the closest real-world equivalent that keeps its shape, colors and placement. People keep their anatomy as it is.

Clean it completely:
- Remove all of the original's noise and grain, color speckles, compression and JPEG artifacts, scattered light or dark dots, enlarged pore-like pits, crinkled or crepey texture, webs of fine lines, flakes or white specks in the hair, blockiness, banding, halos, color fringing, smudges, unwanted blur and moiré. Do not copy that damage from the original: render every surface fresh and clean.
- Fix strange or broken textures: warped, melted, smeared, repeating or garbled patterns become the clean, coherent real-world material they stand for.
- Noise, artifacts and glitches are not details: remove them. Real details (eyes, hair strands, fabric weave, small objects, edges) stay clear and intact; do not blur them away.

Make it read as a real photo:
- Real light: it comes from the sources the scene has (the sun, the sky, a window, lamps, a screen), from the same direction and in the same color as in the original, and it behaves like real light. It falls off with distance, casts shadows as hard or as soft as that source would, leaves contact shadows where things touch, picks up color from nearby surfaces, and leaves the sides turned away from it darker. No flat, even, sourceless light.
- Real materials: natural, healthy skin that looks clean, freshly washed and dry, with a matte finish: no oil, sweat or wet shine, only a faint, soft sheen where the light hits the highest points (unless the user's request asks for wet, sweaty or oiled skin), lit from within like living skin rather than a painted or plastic shell, with the natural variation of tone a real body has, following the same muscles and contours, in the same skin tone as the original, never darker; real fabric weave, seams and folds; real metal, wood, glass, stone, water and foliage.
- Skin texture that fits the distance: pores show only when the camera is close enough for real pores to be seen (a close-up of a face or of a detail), and then as fine, natural pores. From a normal or wider distance (half body, full body, a whole scene) the skin reads smooth and even, without pores, as it does in a real photo taken from that far. Never add pores to fill the resolution.
- One skin for each person: their face, neck, shoulders, arms, hands, torso and legs show the same skin tone, undertone, finish and quality, lit by the same light, with only the small variations a real body has (a little more color on the cheeks, lips, knuckles and knees). Where the original's face and body don't match (a different tone, more shine or oil on one of them, a different texture or level of quality, so that the face looks pasted onto another body), make them match: the whole person takes the skin tone of their face, with one even matte finish and the same quality everywhere. This is not a change to the person: their skin tone is the one their face has.
- Real hair, as a camera records it: a mass made of many individual strands of slightly different thickness and direction, falling and clumping the way real hair does under its own weight, with fine baby hairs at the hairline and a few loose strands at the outer edges; the color varies a little from strand to strand and from root to tip; light catches single strands as soft, broken highlights that follow the curve of the hair, and thin hair against the light lets some of it through. Keep the same style, length, volume and color as the original. Never a smooth, painted, sculpted or plastic mass, a helmet-like sheen, or strands drawn as regular lines.
- Camera: a professional full-frame camera with a prime lens that fits the framing, such as an 85mm for a close portrait, a 50mm for half or full body and a 35mm for a wider scene, at a moderate aperture (around f/2.8 to f/4 for people, f/8 for a wide scene), at low ISO, focused on the eyes when there is a face, with the camera's natural color and contrast. If the user's request asks for a phone photo or another camera, use that instead.
- Exposure: a correctly exposed photograph, as a good photographer sets it for the main subject: bright, clean highlights that keep their detail, shadows that are dark but still show what is in them, and the full tonal range and contrast a real camera records, instead of the flat, even tones or the painted contrast of an illustration. The light keeps its direction, color and mood; only the way the camera records it changes.
- Real optics: natural lens perspective, a depth of field that fits the framing (a close portrait has a softer background, a wide scene stays in focus), believable white balance, and a clean image with smooth tonal transitions. The only grain is the very faint, fine and even grain a real camera leaves at low ISO: the same over the whole frame and noticeable only when zoomed in, never dots, specks, blotches or a texture on the skin.
- An unretouched, true-to-life look, like a photo someone actually took and nobody edited: someone looking at it should believe it came straight from the camera.

Avoid: painterly or illustrated traces, outlines, cel shading, a 3D-render, CGI or video-game look, flat or sourceless lighting, flat or painted contrast, noise, heavy or blotchy grain, compression artifacts, dots or pits on the skin, pores at a distance where they could not be seen, webs of fine lines, specks in the hair, painted, sculpted or plastic-looking hair, oily, wet or sweaty-looking skin, darker skin, a face whose tone, shine or texture differs from the body's, waxy or smeared textures, airbrushed or plastic skin, beauty retouching, HDR, oversharpening, cinematic color grading, and any change to the anatomy, the musculature, the people or the layout.
TEXT;

    public const ENHANCE_STEPS = <<<'TEXT'
- Do not reproduce the damage in the original: noise and grain, scattered light or dark dots and speckles, enlarged pore-like pits, crinkled or crepey texture, webs of fine lines, flakes or white specks in the hair, color speckles, compression and JPEG artifacts, blockiness, banding, halos, color fringing, smudges and moiré. Render those surfaces fresh and clean instead of tracing them.
- Fix strange or broken textures: warped, melted, smeared, repeating or garbled patterns become the clean, coherent material they stand for.
- Skin: smooth, even and natural, clean, freshly washed and dry, with a matte finish (only a faint, soft sheen where the light hits the highest points, unless the user's request asks for wet, sweaty or oiled skin) and texture that fits the distance: pores only when the view is close enough for real pores to show (a close-up of a face or of a detail), and then fine and natural; from farther away the skin reads smooth and even, without pores; the same skin tone as the original, never darker; no oily, wet or sweaty shine. Moles, freckles, scars, veins, muscle definition and every real feature stay as they are.
- One skin for each person: the face, neck, body and limbs show the same skin tone, undertone, finish and quality, with only the small variations a real body has (a little more color on the cheeks, lips, knuckles and knees). Where the original's face and body don't match (a different tone, more shine or oil on one of them, a different texture or level of quality, so that the face looks pasted onto another body), make them match: the whole person takes the skin tone of their face, with one even matte finish and the same quality everywhere. This is not a change to the person: their skin tone is the one their face has.
- Hair: clean, natural strands with soft highlights, free of specks and flakes.
- A clean, well-focused image with smooth tonal transitions and gradients, accurate exposure and color balance. Real details (eyes, hair strands, fabric, edges, small objects) stay clear and intact; do not blur them away.

Avoid: changing the style, adding texture or "detail" that is not part of the scene, pores at a distance where they could not be seen, oily or glossy skin, darker skin, a face whose tone, shine or texture differs from the body's, airbrushed or plastic skin, beauty retouching (no slimming, no reshaping of faces or bodies, no added makeup), oversharpening, an HDR look, heavy color grading, and any change to the people or the layout.
TEXT;

    public const ENHANCE = <<<'TEXT'
Restore and enhance this image without changing what it shows: recreate the same picture from scratch as a clean, new render at its best quality. Use the attached image as the reference for what to show (the people, content, composition, colors and light), not as a surface to copy: its surface may be damaged by noise and artifacts, and that damage must not carry over.

Keep exactly, without exceptions:
- The style and medium (a photo stays a photo, an illustration stays an illustration), composition, framing, crop and perspective.
- Every person's identity: face, facial features and their proportions, expression, gaze, age, skin tone (the same color, not darker), hair, body shape, build, anatomy and musculature, pose and gestures.
- Clothing, accessories, objects, background, colors, lighting, and the position and size of every element.
- Every real detail, however small. Do not add, remove, move or redesign anything, and do not add text, logos or watermarks.

Clean and improve:

TEXT.self::ENHANCE_STEPS;

    public const UPSCALE_PHOTOREAL = <<<'TEXT'
This is also an upscale: the result is larger than the attached image. Use the extra resolution for a clean, natural photograph at that size, with the same framing, and do not add texture or pores to fill it.
TEXT;

    /** Expandir: the new areas around the image. Same as EXPAND_PROMPT in assets/app.js. */
    public const EXPAND = 'Expand the image by filling only the transparent areas outside the original image. Preserve the elements, framing, details and colors of the original region. Continue the scene, lighting, perspective and textures naturally, with no borders, seams or repeated objects.';

    /** An area marked with the brush and nothing written: the area made again as a correct version of itself (INPAINT_PROMPT). */
    public const INPAINT = 'Redo only the area marked by the mask, and nothing else. Generate that area again as a clean, correct and natural version of what it shows: the same subject and elements, in the same place, pose and size, fixed wherever they are malformed, distorted, garbled, smudged or damaged (for example hands and fingers, faces, eyes, teeth, lettering, warped objects, broken textures, seams and artifacts). It must blend seamlessly with the rest of the picture: the same style, light, colors, perspective, focus and texture, with no visible border. Do not add new objects, do not remove what the area shows and do not change its meaning. Everything outside the marked area stays exactly as it is.';

    /** Cobrir with text: the area goes erased, and is drawn from the text that follows (COVER_NOTE). */
    public const COVER_NOTE = "The area marked by the mask was erased on purpose before sending: it is empty (transparent, or flat gray), so nothing of what was there should be guessed, kept or rebuilt. Draw that area anew following the request below, so that it blends seamlessly with the rest of the picture: the same style, light, colors, perspective, focus and texture, with no visible border. Everything outside the marked area stays exactly as it is.\n\nRequest: ";

    /** Cobrir without text: the erased area filled with what surrounds it (FILL_PROMPT). */
    public const FILL = 'The area marked by the mask was erased on purpose before sending: it is empty (transparent, or flat gray). Fill it with what naturally belongs there, continuing the surroundings (background, surfaces, light, perspective and textures), so that it looks as if whatever was there had never been in the picture. Do not add new objects or people, and leave no outline, seam or placeholder. Everything outside the marked area stays exactly as it is.';

    /** The ready-made instructions by name, with the name of the version they make. */
    public const EDITS = ['photoreal'=>[self::PHOTOREAL, 'Photoreal'], 'enhance'=>[self::ENHANCE, 'Enhanced']];

    /**
     * What the person (or the writer) asks goes first, with priority over the ready-made instruction, and once more at
     * the end; the instruction's own "without exceptions" makes room for it. Same as withNotes in assets/app.js.
     */
    public static function withNotes(string $prompt, string $notes): string
    {
        if ($notes === '') return $prompt;
        $prompt = preg_replace('/\b(Preserve|Keep) exactly, without exceptions:/', "$1 exactly, apart from what the user's request above changes:", $prompt, 1) ?? $prompt;
        $prompt = preg_replace('/and any change to ((?:the anatomy, the musculature, )?the people or the layout)\.\z/', "and any change to $1 other than what the user's request asks for.", $prompt, 1) ?? $prompt;
        return "The user's own request for this image. It has priority over everything else in this prompt:\n".$notes."\n\n"
            .'Carry that request out fully, so that it is clearly visible in the result, not subtle. Where it changes something that the instructions below say to keep '
            .'(the anatomy, a part of a body, a face, clothes, colors, light or anything else), the request wins for that part, and "keep" below means the image as it is after that change. '
            .'Everything the request does not mention follows the instructions below.'."\n\n"
            .$prompt."\n\nReminder: the user's request comes first and must be clearly visible in the result:\n".$notes;
    }

    /** The upscale instruction, as Fotorrealista when asked for, with what to improve. Same as upscalePrompt in assets/app.js. */
    public static function upscale(string $instructions, string $extra = 'none'): string
    {
        if ($extra === 'photoreal') return self::withNotes(self::UPSCALE_PHOTOREAL."\n\n".self::PHOTOREAL, $instructions);
        return trim(self::UPSCALE.($extra === 'enhance' ? "\n\nAlso clean and improve it:\n".self::ENHANCE_STEPS."\n\n" : ' ').($instructions !== '' ? 'Requested improvements: '.$instructions : ''));
    }
}
