{* Copyright (c) Anuko International Ltd. https://www.anuko.com
License: See license.txt *}

{if $altcha_widget}
<script src="js/altcha.min.js" async type="module"></script>
<altcha-widget
  name="{$altcha_widget.name|escape:'html'}"
  maxnumber="{$altcha_widget.maxnumber|escape:'html'}"
  challengejson="{$altcha_widget.challengejson|escape:'html'}"
  strings="{$altcha_widget.strings|escape:'html'}"
></altcha-widget>
{/if}
