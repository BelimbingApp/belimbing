@extends('errors.layout')

@section('code', '419')
@section('title', __('Your session expired'))
@section('message', __('You were away for a while, so we signed the form out for safety. Nothing was lost on our side — go back and submit again.'))

@section('primary-href', url()->previous())
@section('primary-label', __('Go back'))
@section('secondary-href', url('/'))
@section('secondary-label', __('Home'))
