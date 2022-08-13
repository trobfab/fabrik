define(["jquery","fab/fabrik"],function(r,a){"use strict";return new Class({Implements:[Events],options:{observe:"",trigger:"",cnn:0,table:0,map:"",editOrig:!1,fillOnLoad:!1,confirm:!0,autofill_lookup_field:0,showNotFound:!1,notFoundMsg:""},initialize:function(t){var e=this;this.options=r.extend(this.options,t),this.attached=[],this.newAttach=[],this.setupDone=!1,this.setUp(a.getBlock("form_"+this.options.formid)),a.addEvent("fabrik.form.elements.added",function(t){e.setUp(t)})},getElement:function(e){var o,n=this,i=this.form.formElements.get(this.options.observe),s=0;return i?this.attached.push(i.options.element):Object.keys(this.form.formElements).each(function(t){t.contains(n.options.observe)&&(o=n.form.formElements.get(t),n.attached.contains(o.options.element)||n.attached.push(o.options.element),"null"!==typeOf(e)&&e!==s||(i=o),s++)}),i},doLookup:function(t){this.lookUp(t)},setUp:function(t){var e=this;if(!this.setupDone&&void 0!==t){try{this.form=t}catch(t){return}this.doLookupEvent=this.doLookup.bind(this);var o,n,i=!1,s=this.form.formElements.get(this.options.observe);s?(this.attached.push(s.options.element),e.newAttach.push(s.options.element)):(o=0,Object.keys(this.form.formElements).each(function(t){t.contains(e.options.observe)&&(i=e.form.formElements.get(t),e.attached.contains(i.options.element)||(e.attached.push(i.options.element),e.newAttach.push(i.options.element)),t=parseInt(i.getRepeatNum(),10),!isNaN(t)&&t!==o||(s=i),o++)})),this.element=s,""===this.options.trigger?this.element?(n=this.element.getBlurEvent(),this.newAttach.each(function(t){e.form.formElements.get(t);e.form.dispatchEvent("",t,n,e.doLookupEvent),e.options.fillOnLoad&&e.form.dispatchEvent("",t,"load",e.doLookupEvent)})):fconsole("autofill - couldnt find element to observe"):(this.form.dispatchEvent("",this.options.trigger,"click",this.doLookupEvent),this.options.fillOnLoad&&this.form.dispatchEvent("",this.options.trigger,"load",this.doLookupEvent)),this.setupDone=!0,this.newAttach=[]}},lookUp:function(t){var e,o,n;this.options.trigger||(this.element=t),!0===this.options.confirm&&!window.confirm(Joomla.JText._("PLG_FORM_AUTOFILL_DO_UPDATE"))||(a.loader.start("form_"+this.options.formid,Joomla.JText._("PLG_FORM_AUTOFILL_SEARCHING")),this.element||(this.element=this.getElement(0)),e=this.element.getValue(),o=this.options.formid,t=this.options.observe,n=this,r.ajax({url:"index.php",method:"post",dataType:"json",data:{option:"com_fabrik",format:"raw",task:"plugin.pluginAjax",plugin:"autofill",method:"ajax_getAutoFill",g:"form",v:e,formid:o,observe:t,cnn:this.options.cnn,table:this.options.table,map:this.options.map,autofill_lookup_field:this.options.autofill_lookup_field}}).always(function(){a.loader.stop("form_"+n.options.formid)}).fail(function(t,e,o){window.alert(e)}).done(function(t){n.updateForm(t)}))},updateForm:function(t){this.json=t,a.fireEvent("fabrik.form.autofill.update.start",[this,t]);var e,o,n,i,s=this.form.formElements.get(t.__elid).getRepeatNum();if(r.isEmptyObject(this.json))this.options.showNotFound&&(i=""===this.options.notFoundMsg?Joomla.JText._("PLG_FORM_AUTOFILL_NORECORDS_FOUND"):this.options.notFoundMsg,window.alert(i));else{for(e in this.json)this.json.hasOwnProperty(e)&&(o=this.json[e],"_raw"===e.substr(e.length-4,4)&&(n=e=e.replace("_raw",""),this.tryUpdate(e,o)||(e=this.updateRepeats(e,o,s,n))));!0===this.options.editOrig&&(this.form.getForm().getElement("input[name=rowid]").value=this.json.__pk_val),a.fireEvent("fabrik.form.autofill.update.end",[this,t])}},updateRepeats:function(t,e,o,n){var i,s;if("object"==typeof e)for(i in e)e.hasOwnProperty(i)&&(s=t+"_"+i,this.tryUpdate(s,e[i]));else t+=o?"_"+o:"_0",this.tryUpdate(t,e)||(t="join___"+this.element.options.joinid+"___"+t,this.tryUpdate(n,e,!0));return t},tryUpdate:function(o,e,t){var n,i=this;if(t=!!t){if(0<(t=Object.keys(this.form.formElements).filter(function(t,e){return t.contains(o)})).length)return t.each(function(t){(n=i.form.elements[t]).update(e),n.baseElementId!==i.element.baseElementId&&(n.element.fireEvent(n.getBlurEvent(),new Event.Mock(n.element,n.getBlurEvent())),n.getBlurEvent()!==n.getChangeEvent()&&n.element.fireEvent(n.getChangeEvent(),new Event.Mock(n.element,n.getChangeEvent())))}),!0}else if(void 0!==(n=this.form.elements[o]))return"auto-complete"===n.options.displayType&&(n.activePopUp=!0),n.update(e),n.baseElementId!==this.element.baseElementId&&(n.element.fireEvent(n.getBlurEvent(),new Event.Mock(n.element,n.getBlurEvent())),n.getBlurEvent()!==n.getChangeEvent()&&n.element.fireEvent(n.getChangeEvent(),new Event.Mock(n.element,n.getChangeEvent()))),!0;return!1}})});