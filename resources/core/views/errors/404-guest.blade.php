{{-- Guest 404: self-contained standalone shell (see errors/404.blade.php). --}}
@extends('errors.layout')

@section('code', '404')
@section('title', $notFoundTitle)
@section('message', $notFoundMessage)
