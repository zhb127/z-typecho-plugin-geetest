<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

include 'lib/class.geetestlib.php';

/**
 * 极验验证插件，用于用户登录
 *
 * @package Geetest
 * @author 菠菜
 * @version 1.0.0
 * @link https://zhb127.com
 *
 */
class Geetest_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 添加插件动作
        // /action/geetest?do=ajaxResponseCaptchaData
        Helper::addAction('geetest', 'Geetest_Action');

        // 注册后台底部结束钩子
        Typecho_Plugin::factory('admin/footer.php')->end = array(__CLASS__, 'renderCaptcha');

        // 注册用户登录成功钩子
        Typecho_Plugin::factory('Widget_User')->loginSucceed = array(__CLASS__, 'verifyCaptcha');

        // 暴露插件函数（用于在自定义表单中渲染极验验证，以及在自定义逻辑中调用极验验证）
        Typecho_Plugin::factory('Geetest')->renderCaptcha = array(__CLASS__, 'renderCaptcha');
        Typecho_Plugin::factory('Geetest')->verifyCaptcha = array(__CLASS__, 'verifyCaptcha');
        Typecho_Plugin::factory('Geetest')->responseCaptchaData = array(__CLASS__, 'responseCaptchaData');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction('geetest');
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $captchaId = new Typecho_Widget_Helper_Form_Element_Text('captchaId', null, '', _t('公钥：'));
        $privateKey = new Typecho_Widget_Helper_Form_Element_Text('privateKey', null, '', _t('私钥：'));

        $dismode = new Typecho_Widget_Helper_Form_Element_Select('dismod', array(
            'float' => '浮动式',
            'embed' => '嵌入式',
            'popup' => '弹出框'
        ), 'float', _t('展现形式：'));

        $cdnUrl = new Typecho_Widget_Helper_Form_Element_Text('cdnUrl', null, '', _t('内容分发地址：'), '注意使用 https 协议。');

        $debugMode = new Typecho_Widget_Helper_Form_Element_Select('debugMode', array(
            '0' => '关闭',
            '1' => '开启'
        ), '0', _t('调试模式：'), '开启时，不会禁用提交按钮，用于测试插件是否生效。');

        $form->addInput($captchaId);
        $form->addInput($privateKey);
        $form->addInput($dismode);
        $form->addInput($cdnUrl);
        $form->addInput($debugMode);
    }

    /**
     * 响应验证码数据
     */
    public static function responseCaptchaData()
    {
        @session_start();

        $pluginOptions = Helper::options()->plugin('Geetest');
        $geetestSdk = new GeetestLib($pluginOptions->captchaId, $pluginOptions->privateKey);

        $widgetRequest = Typecho_Widget::widget('Widget_Options')->request;

        $data = array(
            'user_id' => rand(1000, 9999),
            'client_type' => $widgetRequest->isMobile() ? 'h5' : 'web',
            'ip_address' => $widgetRequest->getIp()
        );

        $_SESSION['gt_server_ok'] = $geetestSdk->pre_process($data, 1);
        $_SESSION['gt_user_id'] = $data['user_id'];

        echo $geetestSdk->get_response_str();
    }

    /**
     * 渲染验证码
     */
    public static function renderCaptcha()
    {
        // 判断是否登录页面
        $widgetOptions = Typecho_Widget::widget('Widget_Options');
        $widgetRequest = $widgetOptions->request;
        $loginUrl = $widgetOptions->loginUrl;
        $currentUrl = $widgetRequest->getRequestUrl();
        if (false === strpos($currentUrl, $loginUrl)) {
            return;
        }

        $pluginOptions = Helper::options()->plugin('Geetest');
        $cdnUrl = ($pluginOptions->cdnUrl ? $pluginOptions->cdnUrl : Helper::options()->pluginUrl . '/Geetest/static/gt.js');
        $debugMode = (bool)($pluginOptions->debugMode);

        $disableButtonJs = '';
        $disableSubmitJs = '';
        if (!$debugMode) {
            $disableButtonJs = 'jqFormSubmit.attr({disabled:true}).addClass("gt-btn-disabled");';
            $disableSubmitJs = <<<EOF
            jqForm.submit(function (e) {
                var validate = captchaObj.getValidate();
                if (!validate) {
                    e.preventDefault();
                }
            });
EOF;
        }

        $ajaxUri = '/action/geetest?do=ajaxResponseCaptchaData';

        echo <<<EOF
        <style rel="stylesheet">
        #gt-captcha { line-height: 44px; }
        #gt-captcha .waiting { background-color: #e8e8e8; color: #4d4d4d; }
        .gt-btn-disabled { background-color: #a3b7c1!important; color: #fff!important; cursor: no-drop!important; }
        </style>
        
        <script src="{$cdnUrl}"></script>
        <script>
        
        // 获取表单提交按钮
        var jqForm = $("form");
        var jqFormSubmit = jqForm.find(":submit");
        
        // 在表单提交按钮之前添加极验验证元素
        jqFormSubmit.parent().before('<div id="gt-captcha"><p class="waiting">正在加载验证码......</p></div>');
        
        // 获取极验验证元素
        var jqGtCaptcha = $("#gt-captcha");
        var jqGtCaptchaWaiting = $("#gt-captcha .waiting");
        var jqGtCaptchaNotice = $("#gt-captcha .notice");
        
        // 定义极验验证初始化回调函数
        var gtInitCallback = function (captchaObj) {
            
            captchaObj.appendTo(jqGtCaptcha);
            
            captchaObj.onSuccess(function () {
                jqFormSubmit.attr({disabled:false}).removeClass("gt-btn-disabled");
            });
            
            captchaObj.onReady(function () {
                jqGtCaptchaWaiting.remove();
                // 禁用表单提交按钮
                $disableButtonJs
            });
            
            $disableSubmitJs
        };
        
        $.ajax({
            url: "{$ajaxUri}&t=" + (new Date()).getTime(),
            type: "get",
            dataType: "json",
            success: function (data) {
                console.log(data);
                initGeetest({
                    gt: data.gt,
                    challenge: data.challenge,
                    new_captcha: data.new_captcha,
                    product: "{$pluginOptions->dismod}",
                    offline: !data.success,
                    width: '100%'
                }, gtInitCallback);
            }
        });
        </script>
EOF;
    }

    /**
     * 验证验证码
     */
    public static function verifyCaptcha()
    {
        if (!self::_verifyCaptcha()) {
            Typecho_Widget::widget('Widget_Notice')->set(_t('验证码错误'), 'error');
            Typecho_Widget::widget('Widget_User')->logout();
            Typecho_Widget::widget('Widget_Options')->response->goBack();
        }
    }

    /**
     * 验证验证码
     *
     * @return int
     */
    private static function _verifyCaptcha()
    {
        // 如果插件渲染失败，则默认验证通过
        if (!isset($_POST['geetest_challenge']) || !isset($_POST['geetest_validate']) || !isset($_POST['geetest_seccode'])) {
            return 1;
        }

        @session_start();

        $pluginOptions = Helper::options()->plugin('Geetest');
        $geetestSdk = new GeetestLib($pluginOptions->captchaId, $pluginOptions->privateKey);

        if (!empty($_SESSION['gt_server_ok'])) {

            $widgetRequest = Typecho_Widget::widget('Widget_Options')->request;
            $clientType = $widgetRequest->isMobile() ? 'h5' : 'web';
            $ipAddress = $widgetRequest->getIp();

            $data = array(
                'user_id' => $_SESSION['gt_user_id'],
                'client_type' => $clientType,
                'ip_address' => $ipAddress
            );

            return $geetestSdk->success_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode'], $data);
        }

        return $geetestSdk->fail_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode']);
    }

}
