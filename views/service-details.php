<?php declare(strict_types=1);$serviceLines=ServiceDetails::lines($serviceDetails ?? null,$en); ?>
<?php if($serviceLines['included']||$serviceLines['excluded']):?><div class="service-details">
<?php foreach(['included','excluded'] as $serviceKey)if($serviceLines[$serviceKey]):?><section><h4><?=$serviceKey==='included'?($en?'Included':'Bao gồm'):($en?'Not included':'Chưa bao gồm')?></h4><ul><?php foreach($serviceLines[$serviceKey] as $serviceLine):?><li><span aria-hidden="true"><?=$serviceKey==='included'?'✓':'−'?></span><span><?=$e($serviceLine)?></span></li><?php endforeach;?></ul></section><?php endif;?>
</div><?php endif;?>
