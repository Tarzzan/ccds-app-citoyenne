<?php
// Mock
function e($str) { return htmlspecialchars((string)$str); }
function generated_visual_url($str) { return "/path/to/$str"; }
$slot = 'login_scene';
$meta = ['label' => 'Login · scene principale', 'fallback' => 'ILL-05'];
$currentAsset = null;
$effectiveAsset = 'ILL-05';
$effectiveUrl = '/path/to/ILL-05';
$isCustomAsset = false;
$activeLabel = 'Défaut';
$assetOptions = [['id'=>'ILL-05','label'=>'Mock','url'=>'/path']];

ob_start();
?>
<div class="visual-admin-slot-field" style="display: flex; flex-direction: column; gap: 8px;">
  <span style="font-weight: 700; font-size: 13px; color: var(--gray-800);"><?= e($meta['label']) ?></span>
  
  <div class="visual-select" data-input-name="slot[<?= e($slot) ?>]">
      <input type="hidden" class="visual-select-input" name="slot[<?= e($slot) ?>]" value="<?= e($currentAsset) ?>">
      <div class="visual-select-trigger">
          <img src="<?= e($currentAsset ? $effectiveUrl : generated_visual_url($meta['fallback'])) ?>" class="trigger-img">
          <div style="flex:1">
              <span class="trigger-label" style="display:block; font-size:13px; font-weight:700; color:var(--gray-800);"><?= e($activeLabel) ?></span>
          </div>
          <span style="color:var(--gray-400);">▼</span>
      </div>
      ... (truncated for test)
  </div>
</div>
<?php
$out = ob_get_clean();
echo "Render OK, length: " . strlen($out) . "\n";
