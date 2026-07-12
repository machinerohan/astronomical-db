<div id="add_entry_fields"<?= isset($show_type) && $show_type !== 'add_entry' ? ' style="display:none"' : '' ?>>
  <h3>New Entry Data</h3>
  <p><label>Name: <input type="text" name="pe_name" size="40"></label></p>
  <p><label>Catalog ID: <input type="text" name="pe_catalog_id" size="20"></label></p>
  <p><label>Entry type:
    <?php if (count($allowed_types) === 1): ?>
      <?= h($allowed_types[0]) ?><input type="hidden" name="pe_entry_type" value="<?= h($allowed_types[0]) ?>">
    <?php else: ?>
      <select name="pe_entry_type">
        <?php foreach ($allowed_types as $et): ?>
          <option value="<?= h($et) ?>"><?= h($et) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </label></p>
  <p><label>RA (J2000): <input type="text" name="pe_right_ascension" size="16" placeholder="06:45:08.9"></label>
     <label>Dec: <input type="text" name="pe_declination" size="16" placeholder="-16:42:58"></label></p>
  <p><label>Mag: <input type="text" name="pe_apparent_mag" size="8"></label>
     <label>Spectral type: <input type="text" name="pe_spectral_type" size="10" placeholder="A0V"></label>
     <label>Constellation: <input type="text" name="pe_constellation" size="8" placeholder="CMa"></label></p>
  <p><label>Distance (ly): <input type="text" name="pe_distance_ly" size="12"></label></p>
  <p><label>Discoverer: <input type="text" name="pe_discovered_by" size="30"></label>
     <label>Year: <input type="number" name="pe_discovery_year" size="6"></label></p>
  <p><label>Notes: <br><textarea name="pe_notes" rows="4" cols="60"></textarea></label></p>
</div>

<div id="edit_field_fields"<?= isset($show_type) && $show_type !== 'edit_field' ? ' style="display:none"' : '' ?>>
  <h3>Edit Field</h3>
  <p><label>Target entry:
    <select name="pfe_entry_id">
      <?php foreach ($entries as $e): ?>
        <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['catalog_id'] ?? '') ?>)</option>
      <?php endforeach; ?>
    </select>
  </label></p>
  <p><label>Field:
    <select name="pfe_field">
      <?php foreach (ENTRY_FIELD_COLUMNS as $f): ?>
        <option value="<?= h($f) ?>"><?= h(ENTRY_FIELD_LABELS[$f] ?? $f) ?></option>
      <?php endforeach; ?>
    </select></label></p>
  <p><label>Old value: <input type="text" name="pfe_old_value" size="30"></label></p>
  <p><label>New value: <input type="text" name="pfe_new_value" size="30"></label></p>
</div>

<div id="remove_entry_fields"<?= isset($show_type) && $show_type !== 'remove_entry' ? ' style="display:none"' : '' ?>>
  <h3>Remove Entry / Revert Field</h3>
  <p><label>Target entry:
    <select name="pr_entry_id">
      <?php foreach ($entries as $e): ?>
        <option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['catalog_id'] ?? '') ?>)</option>
      <?php endforeach; ?>
    </select>
  </label></p>
  <p><label>Field to revert:
    <select name="pr_target_field">
      <option value="">Full entry removal</option>
      <?php foreach (ENTRY_FIELD_COLUMNS as $f): ?>
        <option value="<?= h($f) ?>"><?= h(ENTRY_FIELD_LABELS[$f] ?? $f) ?></option>
      <?php endforeach; ?>
    </select></label></p>
  <p><label>Reason: <br><textarea name="pr_reason" rows="3" cols="60"></textarea></label></p>
</div>
