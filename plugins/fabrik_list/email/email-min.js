var FbListEmail=new Class({Extends:FbListPlugin,initialize:function(a){this.parent(a)},buttonAction:function(){var a="index.php?option=com_fabrik&controller=list.email&task=popupwin&tmpl=component&ajax=1&iframe=1&id="+this.listid+"&renderOrder="+this.options.renderOrder;console.log(a);this.listform.getElements("input[name^=ids]").each(function(c){if(c.get("value")!==false&&c.checked!==false){a+="&ids[]="+c.get("value")}});var b="email-list-plugin";this.windowopts={id:b,title:"Email",loadMethod:"iframe",contentURL:a,width:520,height:420,evalScripts:true,y:100,minimizable:false,collapsible:true,onContentLoaded:function(){this.fitToContent()}};Fabrik.getWindow(this.windowopts)}});