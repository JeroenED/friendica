{{*
 *	AUTOMATICALLY GENERATED TEMPLATE
 *	DO NOT EDIT THIS FILE, CHANGES WILL BE OVERWRITTEN
 *
 *}}
<h3>{{$title}}</h3>

{{foreach $contacts as $c}}
	{{include file="contact_template.tpl" contact=$c}}
{{/foreach}}

<div id="view-contact-end"></div>

{{$paginate}}
