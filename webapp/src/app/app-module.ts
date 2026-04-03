import { NgModule, LOCALE_ID, provideBrowserGlobalErrorListeners } from '@angular/core';
import { BrowserModule } from '@angular/platform-browser';
import { HttpClientModule } from '@angular/common/http';
import { registerLocaleData } from '@angular/common';
import localeDe from '@angular/common/locales/de';

import { AppRoutingModule } from './app-routing-module';
import { App } from './app';

registerLocaleData(localeDe);

@NgModule({
  declarations: [App],
  imports: [
    BrowserModule,
    HttpClientModule,
    AppRoutingModule
  ],
  providers: [
    provideBrowserGlobalErrorListeners(),
    { provide: LOCALE_ID, useValue: 'de' }
  ],
  bootstrap: [App]
})
export class AppModule {}
