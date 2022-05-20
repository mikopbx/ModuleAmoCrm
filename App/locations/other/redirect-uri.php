<!doctype html>
<html lang="en">
<head>
    <title>MikoPBX oAuth AmoCRM</title>
    <script>
        let parent = undefined;
        if(window.parent){
            parent = window.parent;
        }else if(window.opener){
            parent = window.opener;
        }
        if(parent){
            window.parent.postMessage({'code': '<?php echo $_REQUEST['code']??'undefined'; ?>'}, "*");
        }else{
            close();
        }
    </script>
</head>
</html>
