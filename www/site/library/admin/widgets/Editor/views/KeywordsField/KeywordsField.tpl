<?php
    $class = '';
    if($v['is_hidden']->bool()) $class .= ' Item_hidden';
    if($v['is_draft']->bool()) $class .= ' Item_draft';
    if($v['is_link']->bool()) $class .= ' Item_link';
    if($v['is_mandatory']->bool()) $class .= ' Item_mandatory';
?>
<div class="Item Field KeywordsField<?=$class?>" data-v="<?=$v['view_uri']?>" data-o="<?=$v['uri']?>" data-l="<?=$v['link']?>" data-nl="<?=$v['newlink']?>" data-p="KeywordsField">
    <label class="Field__title" for="<?=$v['id']?>"><?=$v['title']?></label>
    <div class="inpt KeywordsField__keywords">
        <div class=" KeywordsField__keywords-old">
        <?php
            $list = $v['views']->arrays(\boolive\values\Rule::string());
            if(!empty($list)){
                foreach ($list as $item){
                    echo $item;
                }
            }
            ?>
        </div>
        <input type="text" class="KeywordsField__keywords-value" value="" id="<?=$v['id']?>">
        <div class="Item__select Field__select"><img width="16" height="16" src="/site/library/admin/widgets/BaseExplorer/views/Item/res/style/img/touch.png" alt=""/></div>
    </div>
    <div class="Item__description Field__description"><?=$v['description']?></div>
    <div class="Field__error"></div>
</div>