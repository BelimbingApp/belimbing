@extends('errors.layout')

@section('code', '403')
@section('title', $forbiddenTitle)
@section('message', __('You do not have permission to access this page. Return home to continue.'))
