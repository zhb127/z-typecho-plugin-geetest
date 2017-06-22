<?php

include 'Plugin.php';

/**
 * 极验验证插件执行
 */
class Geetest_Action implements Widget_Interface_Do
{
    public function execute()
    {
    }

    public function action()
    {
        Geetest_Plugin::responseCaptchaData();
    }
}