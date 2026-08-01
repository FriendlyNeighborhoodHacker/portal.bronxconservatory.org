<?php
// Reusable typeahead field (combobox + results list + hidden id input),
// wired to main.js's bcmTypeahead. $searchUrl must return
// {ok:true, items:[{id,label}]} for ?q=<term>.
require_once __DIR__ . '/partials.php';

function render_typeahead_field(string $idPrefix, string $hiddenName, string $searchUrl, string $placeholder = 'Start typing a name...'): void {
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $idPrefix);
    ?>
    <div class="typeahead-wrap">
      <input type="hidden" name="<?=h($hiddenName)?>" id="<?=h($p)?>_id" value="">
      <input type="text" id="<?=h($p)?>_input" placeholder="<?=h($placeholder)?>"
             role="combobox" aria-expanded="false" aria-owns="<?=h($p)?>_list"
             aria-autocomplete="list" autocomplete="off">
      <div class="typeahead-results" id="<?=h($p)?>_list" role="listbox"></div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      bcmTypeahead({
        input: <?=json_encode($p . '_input')?>,
        hidden: <?=json_encode($p . '_id')?>,
        listEl: <?=json_encode($p . '_list')?>,
        url: <?=json_encode($searchUrl)?>
      });
    });
    </script>
    <?php
}
