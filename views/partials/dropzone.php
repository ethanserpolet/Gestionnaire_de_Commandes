<?php use App\Support\View; ?>
<div class="dropzone" tabindex="0" role="button" aria-label="Ajouter des fichiers">
    <input type="file" name="fichiers[]" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.webp">
    <span class="dz-icon"><?= View::icon('upload', 20) ?></span>
    <span class="dz-title">Glissez vos fichiers ici ou <u>parcourez</u></span>
    <span class="dz-hint">Ou collez une capture d’écran avec <kbd>Ctrl</kbd> + <kbd>V</kbd></span>
    <span class="dz-hint">PDF, PNG, JPG, GIF ou WEBP · 15 Mo max par fichier</span>
    <div class="dz-files"></div>
</div>
