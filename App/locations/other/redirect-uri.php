<!doctype html>
<html lang="en">
<head>
    <title>MikoPBX oAuth AmoCRM</title>
    <script>
        let parent = undefined;
        if(window.opener){
            parent = window.opener;
        }else if(window.parent && window.parent !== window){
            parent = window.parent;
        }
        if(parent){
            parent.postMessage({'code': '<?php echo $_REQUEST['code']??'undefined'; ?>'}, "*");
        }else{
            close();
        }
    </script>
</head>
<body>
<?php echo $_REQUEST['code']??'undefined'; ?>
</body>
</html>
